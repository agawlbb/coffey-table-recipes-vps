"""Real WebSocket snapshots, isolation and replay using disposable SQLite."""
import unittest
import shopping_websocket as shopping


class DataWireTests(unittest.IsolatedAsyncioTestCase):
    server_fixture = 'tests/fixtures/data_socket_server.php'
    asyncSetUp = shopping.ShoppingWireTests.asyncSetUp
    asyncTearDown = shopping.ShoppingWireTests.asyncTearDown
    connect = shopping.ShoppingWireTests.connect
    message = shopping.ShoppingWireTests.message

    async def auth(self, ws, scope='household', token='alice', household='1'):
        await ws.send_json(dict(v=1, type='auth', scope=scope, token=token, household_id=household))
        return await self.message(ws)

    async def request(self, ws, path, body=None, request_id='1'):
        frame = dict(v=1, type='request', id=request_id, path=path)
        if body is not None:
            frame['body'] = body
        await ws.send_json(frame)
        return await self.message(ws)

    async def test_recipe_save_pushes_per_household_snapshot_and_replays_receipt(self):
        ready = await self.auth(self.app)
        self.assertEqual(ready['type'], 'ready')
        self.assertIn('shopping', ready['data'])
        self.assertIn('library', ready['data'])
        second = await self.connect()
        await self.auth(second)
        other = await self.connect()
        await self.auth(other, token='bob', household='2')
        body = dict(title='New soup', source_type='website', original_language='en',
                    measurement_system='unspecified', client_save_id='a'*32,
                    groups=[], ingredients=[], steps=[], actions=[], photos=[], tags=[],
                    category_assignments=[], pairings=[])
        result = await self.request(self.app, '/households/1/recipes', body)
        saved = result['data']['recipe']
        for ws in (self.app, second):
            snapshot = await self.message(ws)
            self.assertEqual(snapshot['type'], 'snapshot')
            self.assertIn(saved['id'], [r['id'] for r in snapshot['data']['library']['recipes']])
        other_snapshot = await self.message(other)
        self.assertEqual(other_snapshot['household_id'], '2')
        self.assertNotIn(saved['id'], [r['id'] for r in other_snapshot['data']['library']['recipes']])
        replay = await self.request(self.app, '/households/1/recipes', body)
        self.assertEqual(replay['data']['recipe'], saved)
        await self.message(self.app)
        bad = await self.request(self.app, '/households/2/recipes')
        self.assertEqual(bad['status'], 404)

    async def test_timer_updates_only_reach_same_account_and_public_is_read_only(self):
        await self.auth(self.app, scope='timers', household=None)
        second = await self.connect()
        await self.auth(second, scope='timers', household=None)
        other = await self.connect()
        await self.auth(other, scope='timers', token='bob', household=None)
        device = 'd'*32
        registered = await self.request(self.app, '/me/timer-devices', dict(
            installation_id=device, platform='android', receive_timers=False, push_token=None))
        self.assertEqual(registered['type'], 'response')
        await self.message(self.app)
        await self.message(second)
        import time
        body = dict(installation_id=device, timer_id=device+':soup', operation_id='e'*32,
                    revision=0, title='Soup', deadline_ms=int(time.time()*1000)+600000, cancelled=False)
        result = await self.request(self.app, '/me/timers', body)
        for ws in (self.app, second):
            self.assertEqual((await self.message(ws))['data']['timers'], [result['data']['timer']])
        # A ping remains the next frame: no other-account timer snapshot was sent.
        await other.send_json(dict(v=1, type='ping'))
        self.assertEqual((await self.message(other))['type'], 'pong')
        public = await self.connect()
        self.assertEqual((await self.auth(public, scope='public', token='', household=None))['type'], 'ready')
        self.assertEqual((await self.request(public, '/recipes/1'))['data']['recipe']['title'], 'Soup')
        self.assertEqual((await self.request(public, '/recipes/1', dict(title='forged')))['status'], 404)

    async def test_new_uploads_are_persistent_and_old_photo_urls_still_work(self):
        await self.auth(self.app)
        old = await self.request(self.app, '/households/1/recipes/1')
        self.assertEqual(old['data']['recipe']['photos'][0]['source_url'], 'https://coffeybean.com/CoffeyTableRecipes/media/recipes/' + 'a'*48 + '.jpg')
        import base64
        from pathlib import Path
        image = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jFuoAAAAASUVORK5CYII=')
        body = dict(title='Photo soup', source_type='website', original_language='en',
                    measurement_system='unspecified', client_save_id='f'*32,
                    groups=[], ingredients=[], steps=[], actions=[], tags=[], category_assignments=[], pairings=[],
                    photos=[dict(id='new-photo', is_cover=True, image_base64=base64.b64encode(image).decode())])
        result = await self.request(self.app, '/households/1/recipes', body)
        self.assertEqual(result['type'], 'response', result)
        photo = result['data']['recipe']['photos'][0]
        self.assertTrue(photo['source_url'].startswith('https://ctr.coffeybean.com/media/recipes/'))
        self.assertTrue(photo['storage_key'].startswith('vps/recipes/'))
        self.assertEqual((Path(self.runtime) / 'state/media/recipes' / photo['source_url'].split('/')[-1]).read_bytes(), image)
        await self.message(self.app)
        read = await self.request(self.app, '/households/1/recipes/' + result['data']['recipe']['id'])
        self.assertEqual(read['data']['recipe']['photos'][0]['source_url'], photo['source_url'])
        path = '/media/recipes/' + photo['source_url'].split('/')[-1]
        async with self.session.get(f'http://127.0.0.1:{self.media_port}' + path) as response:
            self.assertEqual(response.status, 200)
            self.assertEqual(response.headers.get('Access-Control-Allow-Origin'), '*')
            self.assertEqual(response.headers.get('X-Content-Type-Options'), 'nosniff')
            self.assertEqual(await response.read(), image)
        async with self.session.get(f'http://127.0.0.1:{self.media_port}/private/database.json') as response:
            self.assertEqual(response.status, 404)
