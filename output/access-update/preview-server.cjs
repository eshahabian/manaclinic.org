const http=require('http'),fs=require('fs'),path=require('path');
http.createServer((req,res)=>{
 const name=req.url.split('?')[0];
 const target=name==='/'?path.resolve('output/access-update/skype-preview.html'):path.resolve('cpanel','.'+name);
 if(!target.startsWith(path.resolve('cpanel')) && name!=='/'){res.writeHead(403).end();return;}
 fs.readFile(target,(err,data)=>{if(err){res.writeHead(404).end();return;}res.setHeader('Content-Type',({'.css':'text/css','.js':'application/javascript','.html':'text/html','.woff2':'font/woff2'}[path.extname(target)]||'application/octet-stream'));res.end(data);});
}).listen(8787,'127.0.0.1',()=>console.log('Preview http://127.0.0.1:8787'));
