<?php
declare(strict_types=1);

function recipeSourceWebsite(string $value, bool $allowBareDomain=true): ?string {
    $text=trim($value);
    if ($text==='' || strlen($text)>4000 || preg_match('/[\x00-\x20\x7f<>"\\\\]/', $text)
        || preg_match('/%(?![0-9a-f]{2})|%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $text)) return null;
    $explicit=(bool)preg_match('~^https?://~i', $text);
    if (!$explicit && (str_starts_with($text, '/')
        || (preg_match('/^[a-z][a-z0-9+.-]*:/i', $text) && !preg_match('~^[^/:]+:\d+(?:[/?#]|$)~', $text))
        || (!$allowBareDomain && !str_starts_with(strtolower($text), 'www.')))) return null;
    $url=$explicit ? $text : 'https://'.$text;
    $parts=parse_url($url);
    if ($parts===false || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
        || isset($parts['user']) || isset($parts['pass']) || strlen($parts['host'] ?? '')>253
        || (isset($parts['port']) && ($parts['port']<1 || $parts['port']>65535))) return null;
    $labels=explode('.', $parts['host'] ?? '');
    if (count($labels)<2) return null;
    foreach ($labels as $label) if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $label)) return null;
    $ipv4=count($labels)===4;
    foreach ($labels as $label) $ipv4=$ipv4 && preg_match('/^\d{1,3}$/', $label) && (int)$label<=255;
    $suffix=strtolower(end($labels));
    if (!($explicit && $ipv4) && !preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9-]+)$/i', $suffix)) return null;
    if (!$explicit && !str_starts_with(strtolower($text), 'www.')
        && in_array($suffix, ['pdf','doc','docx','txt','jpg','jpeg','png','gif','xlsx','zip'], true)) return null;
    return $url;
}

/** Escape all source text and link only validated website tokens. */
function recipeSourceHtml(string $text): string {
    $escape=static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $whole=recipeSourceWebsite($text);
    if ($whole!==null) {
        $trimmed=trim($text); $start=strpos($text,$trimmed); $end=$start+strlen($trimmed);
        return $escape(substr($text,0,$start)).'<a href="'.$escape($whole).'" target="_blank" rel="noopener noreferrer">'.$escape($trimmed).'</a>'.$escape(substr($text,$end));
    }
    preg_match_all('/\S+/u', $text, $matches, PREG_OFFSET_CAPTURE);
    $tokens=$matches[0]; $html=''; $cursor=0;
    foreach ($tokens as [$token, $offset]) {
        $start=$offset; $end=$offset+strlen($token);
        while ($start<$end && str_contains('([{"\'', $text[$start])) $start++;
        while ($end>$start) {
            $last=$text[$end-1]; $candidate=substr($text, $start, $end-$start);
            $opening=[')'=>'(',']'=>'[','}'=>'{'][$last] ?? null;
            if (str_contains('.;,!?"\'', $last) || ($opening!==null && substr_count($candidate,$opening)<substr_count($candidate,$last))) $end--;
            else break;
        }
        $candidate=substr($text, $start, $end-$start);
        $url=recipeSourceWebsite($candidate, count($tokens)===1 || str_contains($candidate,'/'));
        if ($url===null) continue;
        $html.=$escape(substr($text,$cursor,$start-$cursor));
        $html.='<a href="'.$escape($url).'" target="_blank" rel="noopener noreferrer">'.$escape($candidate).'</a>';
        $cursor=$end;
    }
    return $html.$escape(substr($text,$cursor));
}
