<?php
declare(strict_types=1);

/** Browser pages and short HA bootstrap requests share the existing HTTP listener. */
final class HttpService {
    public function __construct(private PDO|Closure $pdo,private $accounts,private $publicConfig,
        private string $mediaDirectory,private string $associationsDirectory) {}

    public function handle(string $method,string $uri,array $headers=[],string $raw=''): array {
        $headers=array_change_key_case($headers,CASE_LOWER);
        $path=parse_url($uri,PHP_URL_PATH) ?: '/';
        $base=['X-Content-Type-Options'=>'nosniff','Cache-Control'=>'no-store'];
        $origin=$headers['origin']??'';
        if (in_array($origin,['https://coffeybean.com','https://www.coffeybean.com','http://localhost:8080'],true)) {
            $base+=['Access-Control-Allow-Origin'=>$origin,'Vary'=>'Origin',
                'Access-Control-Allow-Headers'=>'Authorization, Content-Type','Access-Control-Allow-Methods'=>'GET, POST, DELETE, OPTIONS'];
        }
        try {
            if ($method==='OPTIONS') return ['status'=>204,'headers'=>$base,'body'=>''];
            if ($path==='/health' || $path==='/api/v1/health') {
                $this->readOnly($method);
                return $this->json(200,['status'=>'ok','service'=>'Coffey Table Recipes','api_version'=>1],$base,$method);
            }
            if (preg_match('#^/recipes/([1-9][0-9]{0,18})$#D',$path,$match)) {
                $this->readOnly($method);
                $recipe=RecipeShare::load($this->database(),$match[1]);
                foreach ($recipe['photos'] as &$photo) {
                    if (preg_match('#^(?:media|vps)/recipes/((?:[a-f0-9]{48}|[a-f0-9]{64}|[a-z][a-z0-9-]*-ai-v[1-9][0-9]*)\.(?:jpg|png|webp))$#D',$photo['storage_key']??'',$file)) $photo['source_url']='https://ctr.coffeybean.com/media/recipes/'.$file[1];
                }
                unset($photo);
                $html=recipeSharePage($recipe);
                return ['status'=>200,'headers'=>$base+$this->pageHeaders()+['Content-Length'=>(string)strlen($html)],'body'=>$method==='HEAD'?'':$html];
            }
            if ($path==='/assets/email/coffey_table.jpg') return $this->file($method,CTR_ROOT.'/assets/email/coffey_table.jpg','image/jpeg',$base);
            if (preg_match('#^/media/recipes/((?:(?:[a-f0-9]{48}|[a-f0-9]{64}|[a-z][a-z0-9-]*-ai-v[1-9][0-9]*)\.(?:jpg|png|webp)|category-[1-9][0-9]*\.jpg))$#D',$path,$match)) {
                $mime=match(strtolower(pathinfo($match[1],PATHINFO_EXTENSION))) {'jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'};
                return $this->file($method,$this->mediaDirectory.'/'.$match[1],$mime,$base+['Access-Control-Allow-Origin'=>'*']);
            }
            if (in_array($path,['/.well-known/assetlinks.json','/.well-known/apple-app-site-association'],true)) return $this->file($method,$this->associationsDirectory.'/'.basename($path),'application/json',$base);
            if (!str_starts_with($path,'/api/v1/')) throw new HttpError(404,'not_found','Page not found.');
            $route=substr($path,7);
            if ($route==='/home-assistant/auth-config') {
                $this->readOnly($method);
                return $this->json(200,($this->publicConfig)(),$base,$method);
            }
            $allowed=($route==='/households' && $method==='GET') ||
                (preg_match('#^/households/[1-9][0-9]*/home-assistant-connections$#D',$route) && in_array($method,['GET','POST'],true)) ||
                (preg_match('#^/households/[1-9][0-9]*/home-assistant-connections/[a-f0-9]{64}$#D',$route) && $method==='DELETE') ||
                ($route==='/home-assistant/connection' && $method==='DELETE');
            if (!$allowed) throw new HttpError(404,'not_found','Endpoint not found.');
            $authorization=$headers['authorization']??'';
            if (!preg_match('/^Bearer (\S{1,16384})$/D',$authorization,$auth)) throw new HttpError(401,'authentication_required','Please sign in.');
            if ($route==='/home-assistant/connection') {
                $connections=new HomeAssistantConnections($this->database());
                $connections->household($auth[1]); $connections->revoke($auth[1]); $data=['revoked'=>true];
            } else {
                $body=null;
                if ($method==='DELETE') $body=[];
                elseif ($method==='POST') {
                    if (strlen($raw)>16384) throw new HttpError(413,'request_too_large','Request is too large.');
                    if (!str_starts_with(strtolower($headers['content-type']??''),'application/json')) throw new HttpError(415,'unsupported_media_type','Use application/json.');
                    $body=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
                    if (!is_array($body) || !str_starts_with(ltrim($raw),'{')) throw new HttpError(400,'invalid_json','Expected a JSON object.');
                }
                $data=($this->accounts)()->handle($auth[1],$route,$body);
            }
            return $this->json(200,$data,$base,$method);
        } catch (HttpError $error) {
            if (str_starts_with($path,'/recipes/') && in_array($method,['GET','HEAD'],true)) return ['status'=>$error->status,'headers'=>$base+$this->pageHeaders(),'body'=>$method==='HEAD'?'':recipeSharePage(['title'=>'Recipe not found','description'=>'This recipe is unavailable or has been removed.'])];
            return $this->json($error->status,['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage()]],$base,$method);
        } catch (JsonException $error) {
            return $this->json(400,['error'=>['code'=>'invalid_json','message'=>'Expected valid JSON.']],$base,$method);
        }
    }
    private function database(): PDO {
        // The HTTP worker outlives MySQL's idle timeout. Keep the factory, not
        // an idle connection, so the next page gets a usable connection.
        return $this->pdo instanceof Closure ? ($this->pdo)() : $this->pdo;
    }
    private function readOnly(string $method): void {
        if (!in_array($method,['GET','HEAD'],true)) throw new HttpError(405,'method_not_allowed','Use GET.');
    }
    private function file(string $method,string $path,string $mime,array $headers): array {
        $this->readOnly($method);
        if (!is_file($path) || is_link($path)) throw new HttpError(404,'not_found','File not found.');
        return ['status'=>200,'headers'=>$headers+['Content-Type'=>$mime]+($method==='HEAD'?['Content-Length'=>(string)filesize($path)]:[]),'file'=>$path];
    }
    private function json(int $status,array $data,array $headers,string $method): array {
        return ['status'=>$status,'headers'=>$headers+['Content-Type'=>'application/json'],'body'=>$method==='HEAD'?'':json_encode($data,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)];
    }
    private function pageHeaders(): array {
        return ['Content-Type'=>'text/html; charset=utf-8','Referrer-Policy'=>'no-referrer','X-Robots-Tag'=>'noindex, nofollow',
            'Content-Security-Policy'=>"default-src 'none'; style-src 'unsafe-inline'; img-src https: http:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"];
    }
}
