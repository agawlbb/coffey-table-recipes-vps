"""AI child-process execution over real sockets, with disposable SQL and a fake provider."""
import asyncio
import hashlib
from pathlib import Path
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

    async def test_recipe_chat_all_households_and_prepare_without_saving(self):
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('UPDATE households SET ai_images_enabled=0 WHERE id=1')
            count = db.execute('SELECT COUNT(*) FROM recipes').fetchone()[0]
        await self.auth(self.app, scope='ai')
        body = dict(request_id='a' * 32, turn_id='1' * 32, mode='create',
                    action='message', message='Help me develop soup', revision=0)
        await self.send_ai('/households/1/recipe-chat', body)
        reply, events = await self.result()
        self.assertEqual(reply['type'], 'response', reply)
        self.assertEqual(reply['data']['revision'], 1)
        self.assertNotIn('draft', reply['data'])
        self.assertIn('Current recipe draft', reply['data']['message'])
        self.assertIn('Agreed soup. 2 cups water', reply['data']['message'])
        self.assertIn('simmer for 2 minutes', reply['data']['message'])
        self.assertTrue(any(e.get('type') == 'progress' for e in events))
        await self.send_ai('/households/1/recipe-chat', {**body, 'turn_id': '2' * 32,
                           'message': 'Show the recipe again.', 'revision': 1})
        repeated, _ = await self.result()
        self.assertEqual(repeated['type'], 'response', repeated)
        self.assertIn('Current recipe draft', repeated['data']['message'])
        self.assertIn('Agreed soup. 2 cups water', repeated['data']['message'])
        await self.send_ai('/households/1/recipe-chat', {**body, 'turn_id': '3' * 32,
                           'action': 'prepare', 'message': '', 'revision': 2})
        prepared, _ = await self.result()
        self.assertEqual(prepared['type'], 'response', prepared)
        recipe = prepared['data']['draft']['recipe']
        self.assertEqual(recipe['source_type'], 'ai_created')
        self.assertIn('edit_options', recipe)
        self.assertNotIn('id', recipe)
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM recipes').fetchone()[0], count)
        for path, changes, status in [
            ('/households/2/recipe-chat', {}, 404),
            ('/households/1/recipe-chat', dict(mode='edit', recipe_id='2'), 404),
            ('/households/1/recipe-chat', dict(mode='variation', recipe_id='2'), 404),
        ]:
            await self.send_ai(path, {**body, **changes})
            denied, _ = await self.result()
            self.assertEqual(denied['status'], status, denied)

    async def test_recipe_chat_variation_and_edit_keep_original_unsaved(self):
        # The fixture must not fetch any real parent photo.
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE recipe_photos SET storage_key='unavailable-fixture'")
        await self.auth(self.app, scope='ai')
        for mode in ['variation', 'edit']:
            body = dict(request_id=('b' if mode == 'edit' else 'c') * 32,
                        turn_id='1' * 32, mode=mode, recipe_id='1',
                        action='message', message='Improve this soup', revision=0)
            await self.send_ai('/households/1/recipe-chat', body)
            response, _ = await self.result()
            self.assertEqual(response['type'], 'response', response)
            await self.send_ai('/households/1/recipe-chat', {**body, 'turn_id': '2' * 32,
                               'action': 'prepare', 'message': '', 'revision': 1})
            prepared, _ = await self.result()
            self.assertEqual(prepared['type'], 'response', prepared)
            recipe = prepared['data']['draft']['recipe']
            if mode == 'edit':
                self.assertEqual(str(recipe['id']), '1')
                self.assertEqual(str(recipe['revision']), '2')
                self.assertEqual(len(recipe['photos']), 1)
            else:
                self.assertEqual(recipe['parent_recipe_id'], '1')
                self.assertEqual(recipe['source_type'], 'ai_variation')
                self.assertNotIn('id', recipe)
            with closing(sqlite3.connect(self.database)) as db:
                self.assertEqual(db.execute('SELECT title,revision FROM recipes WHERE id=1').fetchone(), ('Soup', 2))

    async def test_cooking_context_survives_workers_and_is_private_to_each_user(self):
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("INSERT INTO household_memberships VALUES (1,2,'member'),(2,1,'member')")
        await self.auth(self.app, scope='ai')

        async def chat(message, session, household='1'):
            body = dict(request_id=session * 32, turn_id='1' * 32, mode='create',
                        action='message', message=message, revision=0,
                        user_id='2', cooking_context=[dict(person='self', preference='CLIENT INJECTION')])
            await self.send_ai(f'/households/{household}/recipe-chat', body)
            reply, _ = await self.result()
            self.assertEqual(reply['type'], 'response', reply)
            return reply['data']

        learned = await chat('I dislike mushrooms.', 'd')
        fact_id = learned['context_updates'][0]['id']
        self.assertEqual(learned['context_revision'], 1)
        self.assertNotIn('draft', learned)
        await chat('My son Alex dislikes cilantro.', 'e')
        remembered = await chat('Suggest dinner.', 'f')
        self.assertIn('self: Dislikes mushrooms.', remembered['message'])
        self.assertIn('my son Alex: Dislikes cilantro.', remembered['message'])
        self.assertNotIn('CLIENT INJECTION', remembered['message'])
        self.assertEqual(remembered['context_updates'], [])

        await self.auth(self.app, token='bob', scope='ai')
        other = await chat('Suggest dinner.', '1')
        self.assertNotIn('mushrooms', other['message'])
        self.assertNotIn('cilantro', other['message'])
        await self.auth(self.app, token='alice', household='2', scope='ai')
        household = await chat('Suggest dinner.', '2', household='2')
        self.assertIn('mushrooms', household['message'])
        self.assertNotIn('cilantro', household['message'])

        await self.auth(self.app, scope='ai')
        corrected = await chat('I like mushrooms now.', '3')
        self.assertEqual(corrected['context_updates'][0]['action'], 'update')
        self.assertEqual(corrected['context_updates'][0]['id'], fact_id)
        replay = await chat('I dislike mushrooms.', 'd')
        self.assertEqual(replay, learned)
        current = await chat('Suggest dinner.', '4')
        self.assertIn('Likes mushrooms.', current['message'])
        self.assertNotIn('Dislikes mushrooms.', current['message'])
        await chat('Forget my mushroom preference.', '5')
        forgotten = await chat('Suggest dinner.', '6')
        self.assertNotIn('mushrooms', forgotten['message'])
        self.assertIn('cilantro', forgotten['message'])

    async def test_membership_revocation_during_chat_does_not_commit_context(self):
        await self.auth(self.app, scope='ai')
        await self.send_ai('/households/1/recipe-chat', dict(
            request_id='d' * 32, turn_id='1' * 32, mode='create',
            action='message', message='I dislike mushrooms.', revision=0))
        while (await self.message(self.app)).get('message') != 'Thinking about your recipe…':
            pass
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('DELETE FROM household_memberships WHERE user_id=1 AND household_id=1')
        denied, _ = await self.result()
        self.assertEqual(denied['status'], 404, denied)
        profile = Path(self.runtime) / 'state' / 'recipe-user-context' / (hashlib.sha256(b'1').hexdigest() + '.json')
        self.assertFalse(profile.exists(), 'Revoked requests must not durably learn preferences')

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

    async def test_save_alone_generates_own_category_artwork_and_pushes_snapshot(self):
        await self.auth(self.app, scope='household')
        observer=await self.connect()
        await self.auth(observer,scope='household')
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute("UPDATE recipe_categories SET image_key='main-dishes' WHERE id=10")
        body=dict(title='Mexican recipe', source_type='Pasted text', original_language='en',
                  measurement_system='unspecified', client_save_id='e'*32,
                  groups=[], ingredients=[], steps=[], actions=[], photos=[], tags=[],
                  category_assignments=[dict(label='Mexican',parent_label='Meals')], pairings=[], nutrition=None)
        await self.app.send_json(dict(v=1,type='request',id='save-new-category',path='/households/1/recipes',body=body))
        # Saving does not wait for the 1.2-second fake image generator.
        saved=await asyncio.wait_for(self.message(self.app),.7)
        self.assertEqual(saved['type'],'response',saved)
        category=saved['data']['recipe']['category_assignments'][0]['category_id']
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute('SELECT image_key FROM recipe_categories WHERE id=?',(category,)).fetchone()[0],'')
        await self.app.close()
        while True:
            frame=await self.message(observer)
            self.assertEqual(frame['type'],'snapshot',frame)
            row=next(c for c in frame['data']['library']['categories'] if str(c['id'])==str(category))
            if row['image_key']=='ai-'+str(category):
                break
        self.assertEqual(row['image_url'],f'https://ctr.coffeybean.com/media/recipes/category-{category}.jpg')
        async with self.session.get(f'http://127.0.0.1:{self.media_port}/media/recipes/category-{category}.jpg') as image:
            self.assertEqual(image.status,200)
        # An explicit app follow-up must reuse the owned cover instead of regenerating.
        other=await self.connect()
        await self.auth(other,scope='ai')
        await other.send_json(dict(v=1,type='request',id='ensure-existing',path='/households/1/category-image',body=dict(category_id=str(category))))
        while True:
            result=await asyncio.wait_for(self.message(other),.7)
            if result['type']!='progress':
                break
        self.assertEqual(result['type'],'response',result)

    async def test_failed_artwork_keeps_saved_recipe_and_connection_usable(self):
        await self.auth(self.app,scope='household')
        body=dict(title='Saved despite image failure',source_type='Pasted text',original_language='en',
                  measurement_system='unspecified',client_save_id='g'*32,groups=[],ingredients=[],
                  steps=[],actions=[],photos=[],tags=[],category_assignments=[dict(label='Failure',parent_label='Meals')],pairings=[],nutrition=None)
        await self.app.send_json(dict(v=1,type='request',id='save-failure',path='/households/1/recipes',body=body))
        result=await self.message(self.app)
        self.assertEqual(result['type'],'response',result)
        recipe=result['data']['recipe']['id']
        category=result['data']['recipe']['category_assignments'][0]['category_id']
        self.assertEqual((await self.message(self.app))['type'],'snapshot')
        for _ in range(80):
            self.log.seek(0)
            if b'Automatic category artwork generation failed.' in self.log.read():
                break
            await asyncio.sleep(.05)
        else:
            self.fail('Expected bounded artwork failure was not observed')
        with closing(sqlite3.connect(self.database)) as db:
            self.assertIsNotNone(db.execute('SELECT id FROM recipes WHERE id=?',(recipe,)).fetchone())
            self.assertEqual(db.execute('SELECT image_key FROM recipe_categories WHERE id=?',(category,)).fetchone()[0],'')
        await self.app.send_json(dict(v=1,type='ping'))
        self.assertEqual((await self.message(self.app))['type'],'pong')

    async def test_disabled_image_permission_does_not_reuse_parent_or_generate(self):
        await self.auth(self.app,scope='household')
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('UPDATE households SET ai_images_enabled=0 WHERE id=1')
            db.execute("UPDATE recipe_categories SET image_key='main-dishes' WHERE id=10")
        body=dict(title='Category without image access',source_type='Pasted text',original_language='en',
                  measurement_system='unspecified',client_save_id='f'*32,groups=[],ingredients=[],
                  steps=[],actions=[],photos=[],tags=[],category_assignments=[dict(label='Mexican',parent_label='Meals')],pairings=[],nutrition=None)
        await self.app.send_json(dict(v=1,type='request',id='save-disabled',path='/households/1/recipes',body=body))
        result=await self.message(self.app)
        self.assertEqual(result['type'],'response',result)
        category=result['data']['recipe']['category_assignments'][0]['category_id']
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute('SELECT image_key FROM recipe_categories WHERE id=?',(category,)).fetchone()[0],'')
        self.assertEqual((await self.message(self.app))['type'],'snapshot')
        async with self.session.get(f'http://127.0.0.1:{self.media_port}/media/recipes/category-{category}.jpg') as image:
            self.assertEqual(image.status,404)


if __name__ == '__main__':
    unittest.main()
