"""One real connection carries account, household, cooking and timer traffic."""
import asyncio
import unittest
import shopping_websocket as shopping


class AppWireTests(unittest.IsolatedAsyncioTestCase):
    server_fixture = 'tests/fixtures/app_socket_server.php'
    asyncSetUp = shopping.ShoppingWireTests.asyncSetUp
    async def asyncTearDown(self):
        if any(self.id() in str(test) for test, _ in self._outcome.result.failures + self._outcome.result.errors):
            self.log.seek(0)
            print(self.log.read().decode(errors='replace'))
        await shopping.ShoppingWireTests.asyncTearDown(self)
    connect = shopping.ShoppingWireTests.connect
    message = shopping.ShoppingWireTests.message

    async def auth(self, ws, token='alice'):
        await ws.send_json(dict(v=1,type='auth',scope='app',token=token,household_id=None))
        frame=await self.message(ws)
        self.assertEqual(frame['type'],'ready',frame)
        return frame['data']

    async def command(self, ws, path, body=None, request_id='1'):
        await ws.send_json(dict(v=1,type='request',id=request_id,path=path,**({'body':body} if body is not None else {})))
        snapshots=[]
        while True:
            frame=await self.message(ws)
            if frame['type']=='snapshot': snapshots.append(frame['data'])
            elif frame['type']!='progress': return frame,snapshots

    async def test_request_limit_does_not_disconnect_heartbeats_or_reauthentication(self):
        await self.auth(self.app)
        for i in range(121):
            reply,_=await self.command(self.app,'/me/timers',request_id=str(i))
        self.assertEqual(reply['status'],429)
        self.assertEqual(reply['id'],'120')
        await self.app.send_json(dict(v=1,type='ping'))
        self.assertEqual((await self.message(self.app))['type'],'pong')
        self.assertIn('account',await self.auth(self.app))

    async def test_one_connection_bootstrap_and_bidirectional_changes(self):
        state=await self.auth(self.app)
        self.assertEqual(state['account']['user']['id'],'1')
        self.assertEqual(set(state['households']),{'1'})
        self.assertIn('cooking',state)
        self.assertIn('timers',state)
        other=await self.connect()
        await self.auth(other)
        response,pushes=await self.command(self.app,'/households/1/meal-plan',dict(revision=0,operation='add',id='app-meal',kind='leftovers',date='2099-11-06'))
        self.assertEqual(response['type'],'response',response)
        self.assertEqual(pushes[-1]['households']['1']['library']['responses']['/households/1/meal-plan']['entries'][0]['id'],'app-meal')
        remote=await self.message(other)
        self.assertEqual(remote['type'],'snapshot')
        self.assertEqual(remote['data'],pushes[-1])
        self.assertNotIn('cooking',pushes[-1])
        response,_=await self.command(self.app,'/me/timers')
        self.assertIn('timers',response['data'])
        response,_=await self.command(self.app,'/me/cooking-sessions')
        self.assertIn('sessions',response['data'])
        response,pushes=await self.command(self.app,'/me/name',dict(display_name='Alice Changed'))
        self.assertEqual(response['type'],'response',response)
        self.assertEqual(pushes[-1]['account']['user']['display_name'],'Alice Changed')
        self.assertEqual((await self.message(other))['data']['account']['user']['display_name'],'Alice Changed')

    async def test_four_clients_receive_each_change_without_polling(self):
        peers=[self.app]+[await self.connect() for _ in range(3)]
        await asyncio.gather(*(self.auth(ws) for ws in peers))
        for i,ws in enumerate(peers):
            response,pushes=await self.command(ws,'/households/1/meal-plan',dict(revision=0,operation='add',id=f'meal-{i}',kind='leftovers',date='2099-11-06'))
            self.assertEqual(response['type'],'response',response)
            self.assertEqual(len(pushes[-1]['households']['1']['library']['responses']['/households/1/meal-plan']['entries']),i+1)
            for other in peers:
                if other is ws: continue
                update=await self.message(other)
                self.assertEqual(update['type'],'snapshot')
                self.assertEqual(update['data'],pushes[-1])

    async def test_four_clients_can_each_change_account_without_busy_failures(self):
        peers=[self.app]+[await self.connect() for _ in range(3)]
        await asyncio.gather(*(self.auth(ws) for ws in peers))
        results=await asyncio.gather(*(self.command(ws,'/me/name',dict(display_name=f'Name {i}'),request_id=str(i)) for i,ws in enumerate(peers)))
        for response,_ in results:
            self.assertEqual(response['type'],'response',response)

    async def test_last_update_wins_and_delayed_removal_preserves_new_item(self):
        await self.auth(self.app)
        path='/households/1/shopping-list'
        item=lambda id: dict(id=id,name='Milk',department='',checked=False)
        response,_=await self.command(self.app,path,dict(items=[item('old-milk')]))
        self.assertEqual(response['type'],'response',response)
        response,_=await self.command(self.app,path,dict(revision=0,items=[item('old-milk'),item('new-milk')]))
        self.assertEqual(response['type'],'response',response)
        response,pushes=await self.command(self.app,path,dict(remove_ids=['old-milk']))
        self.assertEqual(response['type'],'response',response)
        self.assertEqual([i['id'] for i in response['data']['items']],['new-milk'])
        self.assertEqual([i['id'] for i in pushes[-1]['households']['1']['shopping']['items']],['new-milk'])

    async def test_anonymous_app_connection_only_exposes_public_recipe(self):
        state=await self.auth(self.app,'')
        self.assertIsNone(state['account'])
        self.assertEqual(state['households'],{})
        response,_=await self.command(self.app,'/recipes/1')
        self.assertEqual(response['data']['recipe']['title'],'Soup')
        rejected,_=await self.command(self.app,'/me/cooking-sessions')
        self.assertEqual(rejected['status'],401)

    async def test_app_snapshot_is_scoped_to_the_authenticated_user(self):
        alice=await self.auth(self.app)
        other=await self.connect()
        bob=await self.auth(other,'bob')
        self.assertEqual(set(alice['households']),{'1'})
        self.assertEqual(set(bob['households']),{'2'})
        self.assertEqual(bob['account']['user']['id'],'2')


if __name__=='__main__': unittest.main()
