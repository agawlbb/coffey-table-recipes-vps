"""Real account WebSockets and workers; disposable SQL and simulated SMTP only."""
import asyncio
from contextlib import closing
import sqlite3
import unittest
import shopping_websocket as shopping
import data_websocket as data


class AccountWireTests(unittest.IsolatedAsyncioTestCase):
    server_fixture = 'tests/fixtures/account_socket_server.php'
    asyncSetUp = shopping.ShoppingWireTests.asyncSetUp
    asyncTearDown = shopping.ShoppingWireTests.asyncTearDown
    connect = shopping.ShoppingWireTests.connect
    async def message(self, ws):
        while True:
            event = await shopping.ShoppingWireTests.message(self, ws)
            if event['type'] != 'progress':
                return event
    auth = data.DataWireTests.auth
    request = data.DataWireTests.request

    async def test_http_ha_setup_and_revoke_on_real_listener(self):
        await self.auth(self.app, 'account', 'new', None)
        household=(await self.request(self.app, '/households', dict(name='Family',person_name='Owner')))['data']['household']['id']
        base=f'http://127.0.0.1:{self.media_port}/api/v1'
        async with self.session.get(base+'/home-assistant/auth-config') as response:
            self.assertEqual(await response.json(), {'api_key':'public-fixture'})
        headers={'Authorization':'Bearer new'}
        async with self.session.get(base+'/households',headers=headers) as response:
            self.assertEqual((await response.json())['households'][0]['id'],household)
        async with self.session.post(base+f'/households/{household}/home-assistant-connections',headers=headers,json={}) as response:
            self.assertEqual(response.status,200)
            token=(await response.json())['token']
        async with self.session.delete(base+'/home-assistant/connection',headers={'Authorization':'Bearer '+token}) as response:
            self.assertEqual(await response.json(),{'revoked':True})

    async def test_slow_admin_reads_report_progress_without_blocking_heartbeat(self):
        await self.auth(self.app,'account','admin',None)
        await self.app.send_json(dict(v=1,type='request',id='users',path='/admin/users'))
        progress=await shopping.ShoppingWireTests.message(self,self.app)
        self.assertEqual(progress['type'],'progress')
        await self.app.send_json(dict(v=1,type='ping'))
        self.assertEqual((await asyncio.wait_for(self.message(self.app),.7))['type'],'pong')
        result=await self.message(self.app)
        self.assertEqual(result['id'],'users')
        self.assertTrue(result['data']['users'])

    async def test_confirmed_delete_finishes_after_admin_disconnects(self):
        await self.auth(self.app,'account','guest',None)
        guest=(await self.request(self.app,'/me'))['data']['user']['id']
        admin=await self.connect()
        await self.auth(admin,'account','admin',None)
        await admin.send_json(dict(v=1,type='request',id='delete',path=f'/admin/users/{guest}/delete',body={'confirm':True}))
        self.assertEqual((await shopping.ShoppingWireTests.message(self,admin))['type'],'progress')
        await asyncio.sleep(.15)
        await admin.close()
        await asyncio.sleep(1.5)
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM users WHERE firebase_uid='guest'").fetchone()[0],0)

    async def test_new_identity_bootstrap_and_setup_without_household(self):
        self.assertEqual((await self.auth(self.app, 'account', 'new', None))['type'], 'ready')
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM users WHERE firebase_uid='new'").fetchone()[0], 0)
        me = await self.request(self.app, '/me')
        self.assertEqual(me['data']['user']['email'], 'new@example.test')
        created = await self.request(self.app, '/households', dict(name='Family', person_name='Owner'))
        self.assertEqual(created['data']['household']['name'], 'Family')
        self.assertEqual((await self.request(self.app, '/me'))['data']['households'][0]['name'], 'Family')
        self.assertEqual((await self.request(self.app, '/households', dict(name='Again', person_name='Owner')))['status'], 409)

    async def test_mail_does_not_block_heartbeat_or_another_connection(self):
        await self.auth(self.app, 'account', 'new', None)
        await self.app.send_json(dict(v=1, type='request', id='mail', path='/me/verification-email', body={}))
        await asyncio.sleep(.15)
        await self.app.send_json(dict(v=1, type='ping'))
        self.assertEqual((await asyncio.wait_for(self.message(self.app), .7))['type'], 'pong')
        other = await self.connect()
        self.assertEqual((await asyncio.wait_for(self.auth(other, 'account', 'guest', None), .7))['type'], 'ready')
        response = await self.message(self.app)
        self.assertEqual(response['id'], 'mail')
        self.assertTrue(response['data']['sent'])

    async def test_identity_scope_and_disabled_account_boundaries(self):
        for token, household, status in [('bad', None, 401), ('disabled', None, 403), ('new', '1', 422)]:
            ws = await self.connect()
            self.assertEqual((await self.auth(ws, 'account', token, household))['status'], status)
        await self.auth(self.app, 'account', 'deleted', None)
        self.assertEqual((await self.request(self.app, '/me'))['error']['code'], 'account_deleted')
        for path in ['/households/1/recipes', '/me/timers']:
            other = await self.connect()
            await self.auth(other, 'account', 'new', None)
            self.assertEqual((await self.request(other, path))['status'], 404)

    async def test_identity_change_hides_previous_mutation_response(self):
        await self.auth(self.app, 'account', 'new', None)
        await self.app.send_json(dict(v=1, type='request', id='old-mail', path='/me/verification-email', body={}))
        await asyncio.sleep(.15)
        self.assertEqual((await self.auth(self.app, 'account', 'guest', None))['type'], 'ready')
        response = await self.request(self.app, '/me', request_id='new-user')
        self.assertEqual(response['id'], 'new-user')
        self.assertEqual(response['data']['user']['email'], 'guest@example.test')
        await asyncio.sleep(1.3)
        await self.app.send_json(dict(v=1, type='ping'))
        self.assertEqual((await self.message(self.app))['type'], 'pong')

    async def test_sql_child_policy_changes_and_admin_reset(self):
        await self.auth(self.app, 'account', 'child', None)
        self.assertTrue((await self.request(self.app, '/me'))['data']['user']['child_mode'])
        self.assertEqual((await self.request(self.app, '/me/verification-email', {}))['status'], 403)
        self.assertFalse((await self.request(self.app, '/me/child-mode', dict(enabled=False, pin='1234')))['data']['enabled'])
        self.assertTrue((await self.request(self.app, '/me/child-mode', dict(enabled=True, pin='5678')))['data']['enabled'])
        other = await self.connect()
        await self.auth(other, 'account', 'new', None)
        with closing(sqlite3.connect(self.database)) as db:
            child_id = db.execute("SELECT id FROM users WHERE firebase_uid='child'").fetchone()[0]
        path = f'/admin/users/{child_id}/reset-child-mode'
        self.assertEqual((await self.request(other, path, dict(confirm=True)))['status'], 403)
        admin = await self.connect()
        await self.auth(admin, 'account', 'admin', None)
        self.assertEqual((await self.request(admin, path, {}))['status'], 422)
        self.assertTrue((await self.request(admin, path, dict(confirm=True)))['data']['reset_pending'])


if __name__ == '__main__':
    unittest.main()
