<?php
declare(strict_types=1);

/** Only occupied dates leave HA; calendar content stays private. */
final class WorkCalendar
{
    public function __construct(private PDO $pdo) {}

    public function read(string $household): array {
        $q=$this->pdo->prepare('SELECT dates_json,color FROM household_work_calendars WHERE household_id=?');
        $q->execute([$household]); $row=$q->fetch(PDO::FETCH_ASSOC);
        return ['dates'=>$row ? json_decode($row['dates_json'],true,512,JSON_THROW_ON_ERROR) : [],
            'color'=>$row['color'] ?? '#E09F3E'];
    }

    public function save(string $household,array $body,bool $ha): array {
        if ($ha) {
            if (array_keys($body)!==['dates'] || !is_array($body['dates']) || !array_is_list($body['dates']) || count($body['dates'])>73050)
                throw new HttpError(422,'invalid_calendar','Send a list of calendar dates.');
            $dates=array_values(array_unique(array_map(static fn($date)=>MealPlanService::date($date),$body['dates'])));
            sort($dates); $column='dates_json'; $value=json_encode($dates,JSON_THROW_ON_ERROR);
        } else {
            if (array_keys($body)!==['color'] || !is_string($body['color']) || !preg_match('/^#[0-9a-fA-F]{6}$/D',$body['color']))
                throw new HttpError(422,'invalid_color','Choose a valid workday color.');
            $column='color'; $value=strtoupper($body['color']);
        }
        $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->pdo->prepare("$insert INTO household_work_calendars (household_id,dates_json,color) VALUES (?,'[]','#E09F3E')")->execute([$household]);
        $this->pdo->prepare("UPDATE household_work_calendars SET $column=? WHERE household_id=?")->execute([$value,$household]);
        return $this->read($household);
    }
}
