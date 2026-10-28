<?php
declare(strict_types=1);

/** Bounded household request journal, independent of shopping-list revisions. */
final class ShoppingSyncRequests
{
    public function __construct(private string $directory) {}

    public function read(string $household): array { return $this->access($household, false); }
    public function request(string $household): array { return $this->access($household, true); }

    private function access(string $household, bool $append): array
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $household)) throw new HttpError(404, 'household_not_found', 'Household not found.');
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) throw new RuntimeException('Sync request storage unavailable.');
        $path = $this->directory.'/'.$household.'.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) throw new RuntimeException('Sync request storage unavailable.');
        try {
            chmod($path, 0600);
            if (!flock($handle, $append ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Cannot lock sync requests.');
            $contents = stream_get_contents($handle);
            $state = $contents === '' ? ['latest_id'=>0, 'requests'=>[]] : json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
            if ($append) {
                $state['requests'][] = ['id'=>++$state['latest_id'], 'requested_at'=>gmdate(DATE_ATOM), 'reason'=>'shopping_list_opened'];
                $state['requests'] = array_slice($state['requests'], -128);
                $json = json_encode($state, JSON_THROW_ON_ERROR);
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) throw new RuntimeException('Cannot save sync request.');
            }
            return $state;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
