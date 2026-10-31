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
        for path in ['/admin/users', '/households/1/recipes', '/me/timers']:
            other = await self.connect()
            await self.auth(other, 'account', 'new', None)
            self.assertEqual((await self.request(other, path))['status'], 404)

    async def test_identity_change_cancels_previous_mail_response(self):
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

    async def test_child_policy_and_policy_outage_fail_closed(self):
        await self.auth(self.app, 'account', 'child', None)
        self.assertTrue((await self.request(self.app, '/me'))['data']['user']['child_mode'])
        self.assertEqual((await self.request(self.app, '/me/verification-email', {}))['status'], 403)
        other = await self.connect()
        await self.auth(other, 'account', 'policy-failed', None)
        self.assertEqual((await self.request(other, '/households', dict(name='Family', person_name='Owner')))['status'], 503)


if __name__ == '__main__':
    unittest.main()
