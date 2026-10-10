import json
from contextlib import closing
from pathlib import Path
import sqlite3
import tempfile
import threading
import unittest
from http.server import ThreadingHTTPServer
import urllib.error
import urllib.request

from panel_admin import ApiError, PanelAdmin, handler_for


class PanelAdminTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        root = Path(self.temp.name)
        self.db = root / 'fixture.sqlite3'
        with closing(sqlite3.connect(self.db)) as c:
            c.executescript('''CREATE TABLE groups(chat_id INTEGER PRIMARY KEY,title TEXT,enabled INTEGER,verify_join INTEGER,verify_message INTEGER,updated_at INTEGER);
            CREATE TABLE events(id INTEGER PRIMARY KEY,event_type TEXT,chat_id INTEGER,chat_title TEXT,telegram_id INTEGER,username TEXT,display_name TEXT,action TEXT,reason TEXT,created_at INTEGER);
            CREATE TABLE pending_joins(chat_id INTEGER,expires_at INTEGER);
            CREATE TABLE registered_users(telegram_id INTEGER,email TEXT);
            INSERT INTO groups VALUES(-6,'Pianyi',1,1,1,1),(-1,'Other',1,1,1,1);
            INSERT INTO registered_users VALUES(1,'untouched@example.invalid');
            INSERT INTO events VALUES(1,'own',-6,'Pianyi',6,'own','Own','ok','own',1),(2,'other',-1,'Other',1,'other','Other','ok','other',1);
            INSERT INTO pending_joins VALUES(-6,9999999999),(-1,9999999999);''')
        self.sites = root / 'sites.json'
        self.sites.write_text(json.dumps([{'id':'pianyi','chat_ids':[-6]},{'id':'other','chat_ids':[-1]}]))
        self.config = {'database':str(self.db),'site':'pianyi','chat_id':-6,'token':'a'*64,'bot_username':'fixture_bot','sites_file':str(self.sites)}
        self.api = PanelAdmin(self.config)
        self.server = ThreadingHTTPServer(('127.0.0.1',0),handler_for(self.api))
        self.worker = threading.Thread(target=self.server.serve_forever,daemon=True);self.worker.start()

    def tearDown(self):
        self.server.shutdown();self.server.server_close();self.worker.join();self.temp.cleanup()

    def request(self,path,body=None,auth=True):
        headers={'Content-Type':'application/json'}
        if auth:headers['Authorization']='Bearer '+self.config['token']
        req=urllib.request.Request('http://127.0.0.1:'+str(self.server.server_port)+path,data=None if body is None else json.dumps(body).encode(),headers=headers)
        try:
            with urllib.request.urlopen(req) as r:return r.status,json.load(r)
        except urllib.error.HTTPError as e:return e.code,json.load(e)

    def payload(self):
        return dict(enabled=True,verify_join=True,verify_message=False,revision=self.api.snapshot()['group']['revision'])

    def test_unauthenticated_and_wrong_tokens_denied(self):
        self.assertEqual(401,self.request('/v1/state',auth=False)[0])
        self.config['token']='b'*64
        self.assertEqual(401,self.request('/v1/state')[0])

    def test_scope_cannot_be_overridden(self):
        for path in ['/v1/state?site=other','/v1/events?chat_id=-1','/v1/events?before=0&before=1']:
            self.assertIn(self.request(path)[0],(404,422))
        for key,value in [('chat_id',-1),('site','other'),('token','secret'),('delete',True)]:
            body=self.payload();body[key]=value
            self.assertEqual(422,self.request('/v1/group',body)[0])

    def test_reads_contain_only_own_group(self):
        status,result=self.request('/v1/state');self.assertEqual(200,status)
        self.assertEqual(-6,result['data']['group']['chat_id']);self.assertEqual(1,result['data']['pending_count'])
        status,result=self.request('/v1/events');self.assertEqual(200,status)
        self.assertEqual(['own'],[r['event_type'] for r in result['data']['items']])
        self.assertNotIn('token',result['data'])

    def test_update_touches_only_own_flags(self):
        status,result=self.request('/v1/group',self.payload());self.assertEqual(200,status);self.assertTrue(result['data']['changed'])
        with closing(sqlite3.connect(self.db)) as c:
            self.assertEqual((1,1,1),c.execute('SELECT enabled,verify_join,verify_message FROM groups WHERE chat_id=-1').fetchone())
            self.assertEqual((1,1,0),c.execute('SELECT enabled,verify_join,verify_message FROM groups WHERE chat_id=-6').fetchone())
            self.assertEqual([(1,'untouched@example.invalid')],c.execute('SELECT * FROM registered_users').fetchall())
            self.assertEqual(2,c.execute('SELECT COUNT(*) FROM pending_joins').fetchone()[0])

    def test_noop_does_not_write_audit_or_timestamp(self):
        body=self.payload();body['verify_message']=True
        self.assertFalse(self.request('/v1/group',body)[1]['data']['changed'])
        with closing(sqlite3.connect(self.db)) as c:
            self.assertEqual(1,c.execute('SELECT updated_at FROM groups WHERE chat_id=-6').fetchone()[0])
            self.assertEqual(2,c.execute('SELECT COUNT(*) FROM events').fetchone()[0])

    def test_concurrent_update_rejected(self):
        body=self.payload();self.assertEqual(200,self.request('/v1/group',body)[0])
        self.assertEqual(409,self.request('/v1/group',body)[0])

    def test_boolean_values_are_strict(self):
        for value in ['false','true',0,1,None,[],{}]:
            body=self.payload();body['enabled']=value
            self.assertEqual(422,self.request('/v1/group',body)[0])

    def test_changed_mapping_fails_closed(self):
        self.sites.write_text(json.dumps([{'id':'other','chat_ids':[-6]}]))
        self.assertEqual(503,self.request('/v1/state')[0])
        self.assertEqual(503,self.request('/v1/events')[0])
        self.assertEqual(503,self.request('/v1/group',dict(enabled=True,verify_join=True,verify_message=True,revision='0'*64))[0])

    def test_pagination_rejects_unbounded_and_invalid_queries(self):
        for query in ['limit=0','limit=101','before=-1','before=abc','limit=999999999','site=other']:
            self.assertEqual(422,self.request('/v1/events?'+query)[0])

    def test_database_missing_does_not_create_new_file(self):
        target=Path(self.temp.name)/'missing.sqlite3'
        with self.assertRaises(FileNotFoundError):PanelAdmin(dict(self.config,database=str(target)))
        self.assertFalse(target.exists())


if __name__=='__main__':unittest.main()
