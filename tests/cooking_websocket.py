"""Real cooking-session broadcasts and isolation over the existing server socket."""
import uuid
import unittest
import data_websocket as data

class CookingWireTests(unittest.IsolatedAsyncioTestCase):
    server_fixture = 'tests/fixtures/data_socket_server.php'
    asyncSetUp = data.DataWireTests.asyncSetUp
    asyncTearDown = data.DataWireTests.asyncTearDown
    connect = data.DataWireTests.connect
    message = data.DataWireTests.message
    auth = data.DataWireTests.auth
    request = data.DataWireTests.request
    # Run only cooking cases here; existing wire tests are run separately.
    async def test_cooking_joins_steps_and_end_broadcast(self):
        await self.auth(self.app, scope='cooking', household=None)
        second = await self.connect()
        await self.auth(second, scope='cooking', household=None)
        other = await self.connect()
        await self.auth(other, scope='cooking', token='bob', household=None)
        session = uuid.uuid4().hex
        device = uuid.uuid4().hex
        async def change(ws, path, **body):
            return await self.request(ws, path, dict(body, installation_id=device, operation_id=uuid.uuid4().hex))
        response = await change(self.app, '/me/cooking-sessions',session_id=session,household_id='1',recipe_id='1')
        self.assertEqual(response['type'], 'response')
        self.assertEqual(response['data']['session']['id'],session)
        await self.message(self.app)
        discovered = await self.message(second)
        self.assertEqual(discovered['data']['sessions'][0]['joined_devices'], [device])
        self.assertEqual((await self.message(other))['data']['sessions'], [])
        device = uuid.uuid4().hex
        await change(second, f'/me/cooking-sessions/{session}/join')
        for ws in (self.app, second, other): await self.message(ws)
        checked = await change(second, f'/me/cooking-sessions/{session}/step',step_id='1',checked=True,revision=0)
        self.assertEqual(checked['data']['session']['completed'], ['1'])
        for ws in (self.app,second):
            self.assertEqual((await self.message(ws))['data']['sessions'][0]['completed'],['1'])
        self.assertEqual((await self.message(other))['data']['sessions'],[])
        await change(second, f'/me/cooking-sessions/{session}/end')
        for ws in (self.app,second):
            ended = (await self.message(ws))['data']
            self.assertEqual(ended['sessions'],[])
            self.assertEqual(ended['ended_sessions'][0]['id'],session)
        self.assertEqual((await self.message(other))['data']['ended_sessions'],[])

    async def test_server_expires_inactive_sessions_without_a_client_write(self):
        import asyncio
        import json
        from pathlib import Path
        await self.auth(self.app, scope='cooking', household=None)
        session=uuid.uuid4().hex
        await self.request(self.app,'/me/cooking-sessions',dict(session_id=session,recipe_id='1',household_id='1',installation_id=uuid.uuid4().hex,operation_id=uuid.uuid4().hex))
        await self.message(self.app)
        file=Path(self.runtime)/'state/cooking/sessions.json'
        state=json.loads(file.read_text())
        state[session]['last_open']=0
        state[session]['idle_ms']=1
        for device in state[session]['devices'].values(): device['open_until']=0
        file.write_text(json.dumps(state))
        frame=await asyncio.wait_for(self.app.receive(),35)
        data=json.loads(frame.data)
        self.assertEqual(data['type'],'snapshot')
        self.assertEqual(data['data']['sessions'],[])
        self.assertEqual(data['data']['ended_sessions'][0]['id'],session)
