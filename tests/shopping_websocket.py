"""Wire-level server checks using ephemeral SQLite, never the hosted database.

Run with a Python environment providing aiohttp and PHP Composer dependencies.
"""
import asyncio
from contextlib import closing
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import unittest

import aiohttp

ROOT = Path(__file__).resolve().parents[1]


class ShoppingWireTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        with socket.socket() as probe:
            probe.bind(("127.0.0.1", 0))
            self.port = probe.getsockname()[1]
        fd, self.database = tempfile.mkstemp(prefix="ctr-wire-")
        os.close(fd)
        self.runtime = tempfile.mkdtemp(prefix="ctr-wire-runtime-")
        self.log = tempfile.TemporaryFile()
        self.process = subprocess.Popen(
            ["php", "tests/fixtures/shopping_socket_server.php", "start"], cwd=ROOT,
            env={**os.environ, "CTR_LISTEN": f"websocket://127.0.0.1:{self.port}", "CTR_TEST_DATABASE": self.database, "CTR_RUNTIME_DIR": self.runtime},
            stdout=self.log, stderr=self.log, start_new_session=True)
        self.session = aiohttp.ClientSession()
        self.sockets = []
        self.url = f"http://127.0.0.1:{self.port}/shopping"
        for _ in range(100):
            try:
                self.app = await self.connect()
                break
            except aiohttp.ClientError:
                await asyncio.sleep(.05)
        else:
            raise AssertionError("Test shopping server did not start")

    async def asyncTearDown(self):
        for ws in self.sockets:
            await ws.close()
        await self.session.close()
        import signal
        os.killpg(self.process.pid, signal.SIGTERM)
        await asyncio.to_thread(self.process.wait, 5)
        self.log.close()
        os.unlink(self.database)
        shutil.rmtree(self.database + "-requests", ignore_errors=True)
        shutil.rmtree(self.runtime, ignore_errors=True)

    async def connect(self):
        ws = await self.session.ws_connect(self.url)
        self.sockets.append(ws)
        return ws

    async def message(self, ws):
        frame = await asyncio.wait_for(ws.receive(), 5)
        self.assertEqual(frame.type, aiohttp.WSMsgType.TEXT)
        return json.loads(frame.data)

    async def authenticate(self, ws, token="alice", household="1"):
        await ws.send_json({"v": 1, "type": "auth", "token": token, "household_id": household})
        return await self.message(ws)

    async def test_app_ha_push_receipt_replay_and_revocation(self):
        import time
        self.assertEqual((await self.authenticate(self.app))["type"], "ready")
        ha = await self.connect()
        self.assertEqual((await self.authenticate(ha, "ctrha_" + "a"*64))["type"], "ready")
        milk = {"id": "milk", "name": "Milk", "department": "", "checked": False}
        body = {"revision": 0, "items": [milk], "base_items": [], "client_change_id": "stable-change", "edited_at_ms": int(time.time()*1000)}
        await self.app.send_json({"v": 1, "type": "request", "id": "save", "path": "/households/1/shopping-list", "body": body})
        response = await self.message(self.app)
        self.assertEqual(response["data"]["acknowledged_change_id"], "stable-change")
        self.assertEqual((await self.message(ha))["data"]["items"], [milk])
        await self.message(self.app)  # Own committed push.
        await self.app.close()
        self.app = await self.connect()
        self.assertEqual((await self.authenticate(self.app))["data"]["revision"], 1)
        await self.app.send_json({"v": 1, "type": "request", "id": "retry", "path": "/households/1/shopping-list", "body": body})
        self.assertEqual((await self.message(self.app))["data"]["revision"], 1)
        await self.message(self.app)
        await self.message(ha)
        milk["checked"] = True
        await ha.send_json({"v": 1, "type": "request", "id": "ha-write", "path": "/households/1/shopping-list",
                            "body": {"revision": 1, "items": [milk]}})
        self.assertEqual((await self.message(ha))["type"], "response")
        self.assertTrue((await self.message(self.app))["data"]["items"][0]["checked"])
        await self.message(ha)
        with closing(sqlite3.connect(self.database)) as db:
            db.execute("DELETE FROM shopping_connections")
            db.commit()
        await ha.send_json({"v": 1, "type": "ping"})
        self.assertEqual((await self.message(ha))["status"], 401)

    async def test_unauthorized_clients_and_cross_household_access(self):
        self.assertEqual((await self.authenticate(self.app, "wrong"))["status"], 401)
        second = await self.connect()
        self.assertEqual((await self.authenticate(second, "bob"))["status"], 404)
        third = await self.connect()
        self.assertEqual((await self.authenticate(third))["type"], "ready")
        await third.send_json({"v": 1, "type": "request", "id": "cross", "path": "/households/2/shopping-list"})
        self.assertEqual((await self.message(third))["status"], 404)

    @unittest.skipUnless(os.getenv('CTR_HA_SOURCE'), 'Set CTR_HA_SOURCE to test the actual HA client')
    async def test_actual_ha_client_and_app_exchange_committed_changes(self):
        import importlib.util
        import sys
        import types
        from copy import deepcopy
        package = types.ModuleType('ctr_ha_wire')
        package.__path__ = [str(Path(os.environ['CTR_HA_SOURCE']) / 'custom_components/coffey_table_recipes')]
        sys.modules[package.__name__] = package
        spec = importlib.util.spec_from_file_location('ctr_ha_wire.api', Path(package.__path__[0]) / 'api.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        class Store:
            value = None
            async def async_load(self): return deepcopy(self.value)
            async def async_save(self, value): self.value = deepcopy(value)
        received = asyncio.Event()
        client = module.Client(self.session, 'ctrha_' + 'a'*64, store=Store(),
                               on_change=lambda state: received.set() if state['items'] and state['items'][0]['checked'] else None)
        client.socket.url = self.url
        try:
            self.assertEqual((await self.authenticate(self.app))['type'], 'ready')
            await client.read()
            local = await client.mutate('add', name='Eggs')
            self.assertEqual(local['items'][0]['name'], 'Eggs')
            push = await self.message(self.app)
            self.assertEqual(push['data']['items'][0]['name'], 'Eggs')
            confirmed = await client.read()
            self.assertEqual(client._queue, [])
            items = confirmed['items']
            items[0]['checked'] = True
            await self.app.send_json({'v': 1, 'type': 'request', 'id': 'check',
                                      'path': '/households/1/shopping-list',
                                      'body': {'revision': confirmed['revision'], 'items': items}})
            self.assertEqual((await self.message(self.app))['type'], 'response')
            await self.message(self.app)
            await asyncio.wait_for(received.wait(), 5)
            self.assertTrue(client.local_state()['items'][0]['checked'])
        finally:
            await client.close()


if __name__ == "__main__":
    unittest.main()
