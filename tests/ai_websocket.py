"""AI child-process execution over real sockets, with disposable SQL and a fake provider."""
import asyncio
from contextlib import closing
import sqlite3
import unittest
import shopping_websocket as shopping
import data_websocket as data


class AiWireTests(unittest.IsolatedAsyncioTestCase):
    server_fixture = 'tests/fixtures/ai_socket_server.php'
    asyncSetUp = shopping.ShoppingWireTests.asyncSetUp
    asyncTearDown = shopping.ShoppingWireTests.asyncTearDown
    connect = shopping.ShoppingWireTests.connect
    auth = data.DataWireTests.auth

    async def message(self, ws):
        try:
            return await shopping.ShoppingWireTests.message(self, ws)
        except AssertionError:
            self.log.seek(0)
            print(self.log.read().decode())
            raise

    async def send_ai(self, path='/households/1/recipe-import', body=None, request_id='ai'):
        await self.app.send_json(dict(v=1, type='request', id=request_id, path=path,
                                      body=body or dict(kind='text', text='Soup with water.')))

    async def result(self):
        events = []
        while True:
            event = await self.message(self.app)
            events.append(event)
            if event['type'] in ('response', 'error'):
                return event, events

    async def test_progress_heartbeats_and_clarification_on_same_connection(self):
        with closing(sqlite3.connect(self.database)) as db:
            initial_count = db.execute('SELECT COUNT(*) FROM recipes').fetchone()[0]
        self.assertEqual((await self.auth(self.app, scope='ai'))['type'], 'ready')
        await self.send_ai()
        first = await self.message(self.app)
        self.assertEqual(first['type'], 'progress')
        self.assertEqual(first['id'], 'ai')
        # A heartbeat on the AI connection and a read on another connection both complete during AI work.
        await self.app.send_json(dict(v=1, type='ping'))
        while (await asyncio.wait_for(self.message(self.app), .7))['type'] != 'pong':
            pass
        other = await self.connect()
        self.assertEqual((await asyncio.wait_for(self.auth(other), .7))['type'], 'ready')
        await other.send_json(dict(v=1, type='ping'))
        self.assertEqual((await asyncio.wait_for(self.message(other), .7))['type'], 'pong')
        response, events = await self.result()
        self.assertEqual(response['type'], 'response', response)
        draft = response['data']
        self.assertEqual(draft['questions'], ['How much water?'])
        self.assertIn('edit_options', draft['recipe'])
        self.assertTrue(any(e.get('message') == 'Checking the recipe draft…' for e in events))
        await self.send_ai(body=dict(kind='answers', recipe=draft['recipe'], answers=['One cup']), request_id='clarify')
        response, _ = await self.result()
        self.assertEqual(response['type'], 'response', response)
        self.assertEqual(response['id'], 'clarify')
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM recipes').fetchone()[0], initial_count)

    async def test_household_admin_and_image_permissions(self):
        await self.auth(self.app, scope='ai')
        for path, body, status in [
            ('/households/2/recipe-import', dict(kind='text', text='Soup'), 404),
            ('/admin/parent-categories', {}, 403),
        ]:
            await self.send_ai(path, body)
            response, _ = await self.result()
            self.assertEqual(response['status'], status, response)
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('UPDATE households SET ai_images_enabled=0 WHERE id=1')
        await self.send_ai('/households/1/recipe-photo', dict(recipe=dict(title='Soup', photos=[])))
        response, _ = await self.result()
        self.assertEqual(response['status'], 403, response)

    async def test_revoked_membership_during_ai_does_not_return_draft(self):
        await self.auth(self.app, scope='ai')
        await self.send_ai()
        while True:
            event = await self.message(self.app)
            if event.get('message') == 'Preparing recipe with AI…':
                break
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('DELETE FROM household_memberships WHERE user_id=1 AND household_id=1')
        response, _ = await self.result()
        self.assertEqual(response['status'], 404, response)

    async def test_images_categories_and_blank_save_run_on_vps(self):
        await self.auth(self.app, scope='ai')
        await self.send_ai('/households/1/recipe-photo', dict(recipe=dict(
            title='Soup', photos=[], ingredients=[dict(original_text='Water')],
            steps=[dict(instruction='Simmer')],
        )))
        response, events = await self.result()
        self.assertEqual(response['type'], 'response', response)
        self.assertTrue(response['data']['photo']['image_base64'])
        self.assertTrue(any(e.get('message') == 'Generating the image…' for e in events))
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE users SET account_role='administrator' WHERE id=1")
            db.execute("UPDATE recipe_categories SET slug='custom-fixture',image_key='' WHERE id=10")
        await self.send_ai('/admin/parent-categories/10/category-image', {}, request_id='cover')
        response, _ = await self.result()
        self.assertEqual(response['type'], 'response', response)
        self.assertEqual(response['data']['image_url'], 'https://ctr.coffeybean.com/media/recipes/category-10.jpg')
        async with self.session.get(f'http://127.0.0.1:{self.media_port}/media/recipes/category-10.jpg') as image:
            self.assertEqual(image.status, 200)
            self.assertEqual(image.headers['Access-Control-Allow-Origin'], '*')
            self.assertTrue((await image.read()).startswith(b'\xff\xd8'))
        # Blank saves use the household socket/outbox but categorize inside the same isolated AI worker.
        other = await self.connect()
        await self.auth(other)
        await other.send_json(dict(v=1, type='request', id='blank', path='/households/1/recipes', body=dict(
            title='Soup', source_type='blank', original_language='en', measurement_system='unspecified',
            client_save_id='d'*32, groups=[], ingredients=[], steps=[], actions=[], photos=[],
            tags=[], category_assignments=[], pairings=[], nutrition=None,
        )))
        while True:
            event = await self.message(other)
            if event['type'] != 'progress':
                break
        self.assertEqual(event['type'], 'response', event)
        self.assertEqual(event['data']['recipe']['title'], 'Soup')
        self.assertTrue(event['data']['recipe']['category_assignments'])

    async def test_worker_failure_does_not_hang_and_connection_stays_usable(self):
        await self.auth(self.app, scope='ai')
        await self.send_ai('/households/1/recipe-category', dict(title=[]))
        response, _ = await self.result()
        self.assertEqual(response['status'], 422, response)
        await self.send_ai(body=dict(kind='invalid'))
        response, _ = await self.result()
        self.assertEqual(response['status'], 422, response)
        await self.send_ai(request_id='retry')
        response, _ = await self.result()
        self.assertEqual(response['type'], 'response', response)


if __name__ == '__main__':
    unittest.main()
