<?php
declare(strict_types=1);
final class RestaurantService {
 public function __construct(private PDO $pdo,private HouseholdService $households) {}
 public function read(string $user,string $household): array {
  $this->households->membership($user,$household);
  $q=$this->pdo->prepare('SELECT revision,items_json FROM household_restaurant_lists WHERE household_id=?');$q->execute([$household]);$row=$q->fetch(PDO::FETCH_ASSOC);
  return ['revision'=>$row?(int)$row['revision']:0,'items'=>$row?json_decode($row['items_json'],true,512,JSON_THROW_ON_ERROR):[]];
 }
 public function save(string $user,string $household,array $body): array {
  $this->households->membership($user,$household);
  if (!isset($body['revision']) || !is_int($body['revision']) || !isset($body['items']) || !is_array($body['items']) || array_values($body['items'])!==$body['items'] || count($body['items'])>200) throw new HttpError(422,'invalid_restaurants','Review the restaurant list.');
  $items=[];$seen=[];
  foreach($body['items'] as $item) {
   $id=$item['id']??null;$name=trim((string)($item['name']??''));$logo=$item['logo_reference']??null;
   if (!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$id)||isset($seen[$id])||$name===''||mb_strlen($name)>160||($logo!==null&&(!is_string($logo)||strlen($logo)>2048))) throw new HttpError(422,'invalid_restaurant','Enter a restaurant name up to 160 characters.');
   $seen[$id]=true;$items[]=['id'=>$id,'name'=>$name,'logo_reference'=>$logo];
  }
  $this->pdo->beginTransaction();
  try {
   $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
   $this->pdo->prepare("$insert INTO household_restaurant_lists (household_id,revision,items_json) VALUES (?,0,'[]')")->execute([$household]);
   $q=$this->pdo->prepare('UPDATE household_restaurant_lists SET revision=revision+1,items_json=? WHERE household_id=? AND revision=?');$q->execute([json_encode($items,JSON_THROW_ON_ERROR),$household,$body['revision']]);
   if ($q->rowCount()!==1) throw new HttpError(409,'restaurant_conflict','The restaurant list changed. Review and try again.');
   $this->pdo->commit();return ['revision'=>$body['revision']+1,'items'=>$items];
  }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
 }
}
