<?php
declare(strict_types=1);

/** Source durations only: no estimated cooking times or unrelated stages. */
final class TimerRanges
{
    public static function extract(string $text): array
    {
        $text=strtr($text,['½'=>' 1/2','¼'=>' 1/4','¾'=>' 3/4']);
        $number='(?:\\d+\\s+\\d+/\\d+|\\d+/\\d+|\\d+(?:\\.\\d+)?)';
        $unit='(?:seconds?|secs?|minutes?|mins?|hours?|hrs?)';
        $amount=static function(string $value): float {
            $parts=preg_split('~\\s+~',trim($value)); $last=array_pop($parts);
            if (str_contains($last,'/')) { [$n,$d]=array_map('floatval',explode('/',$last)); return $d>0 ? (float)($parts[0]??0)+$n/$d : 0; }
            return (float)$last;
        };
        $seconds=static fn($n,$u)=>(int)round($amount($n)*(preg_match('~^h~i',$u)?3600:(preg_match('~^m~i',$u)?60:1)));
        $ranges=[];
        preg_match_all('~(?<![\\d.\\/])('.$number.')\\s*('.$unit.')?\\s*(?:to|[-–—]|and(?=\\s+'.$number.'))\\s*('.$number.')\\s*('.$unit.')\\b~iu',$text,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach ($matches as $m) {
            // "and" is a range only when introduced by "between".
            if (preg_match('~\\band\\b~i',$m[0][0]) && !preg_match('~between\\s*$~i',substr($text,0,$m[0][1]))) continue;
            $min=$seconds($m[1][0],$m[2][0]?:$m[4][0]); $max=$seconds($m[3][0],$m[4][0]);
            [$min,$max]=[min($min,$max),max($min,$max)];
            if ($min>0 && $max>$min && $max<=1000000000) $ranges[]=['min'=>$min,'max'=>$max,'text'=>$m[0][0]];
        }
        // A check followed by a bounded continuation of the same stage.
        preg_match_all('~(?<![\\d.])('.$number.')\\s*('.$unit.')\\b([^.!?]{0,240}?\\bcheck\\b[^.!?]{0,180}?(?:\\badd|\\bcontinue(?: cooking)?(?: for)?|\\bcook(?: for)?)\\s+up to\\s+('.$number.')\\s*(?:more\\s+|additional\\s+)?('.$unit.')\\b)~iu',$text,$matches,PREG_SET_ORDER);
        foreach ($matches as $m) {
            // Do not cross another stated duration or a heat/ingredient change.
            $prefix=substr($m[3],0,strripos($m[3],'up to'));
            if (preg_match('~'.$number.'\\s*'.$unit.'\\b|\\b(?:flip|uncover|reduce heat|increase heat|add (?!up to))\\b~iu',$prefix)) continue;
            $min=$seconds($m[1],$m[2]); $extra=$seconds($m[4],$m[5]);
            if ($min>0 && $extra>0 && $min+$extra<=1000000000) $ranges[]=['min'=>$min,'max'=>$min+$extra,'extra'=>$extra,'text'=>$m[0]];
        }
        return $ranges;
    }
}
