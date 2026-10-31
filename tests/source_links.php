<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/RecipeSharePage.php';
$checks=0;
function checkSource(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
$cases=json_decode(file_get_contents(__DIR__.'/fixtures/source_link_cases.json'),true,512,JSON_THROW_ON_ERROR);
foreach ($cases as $case) {
    $actual=recipeSourceWebsite($case['value']);
    // PHP retains case in a safe URL; browsers treat scheme/host case equally.
    if ($actual!==null) $actual=preg_replace_callback('~^([^:]+)://([^/]+)~',static fn($m)=>strtolower($m[1]).'://'.strtolower($m[2]),$actual);
    checkSource($actual===$case['url'],'Unexpected URL recognition for '.$case['value']);
}
checkSource(str_contains(recipeSourceHtml('  https://example.com/recipe!?x=1,  '),'href="https://example.com/recipe!?x=1,"'),'Meaningful URL punctuation trimmed');
$text="Book & author. See (https://example.com/recipe_(old)), or www.example.org.\nPage 42.";
$html=recipeSourceHtml($text);
checkSource(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8')===$text,'Source text or punctuation changed');
checkSource(substr_count($html,'<a ')===2 && str_contains($html,'href="https://example.com/recipe_(old)"'),'Inline links incorrect');
checkSource(substr_count($html,'target="_blank" rel="noopener noreferrer"')===2,'Unsafe link target');
foreach (['javascript:https://example.com','data:text/html,<script>alert(1)</script>','Family recipe.pdf, p. 42'] as $plain) {
    checkSource(!str_contains(recipeSourceHtml($plain),'<a '),'Unsafe/non-web source linked');
}
$recipe=['title'=>'Fixture','ingredients'=>[],'steps'=>[],
    'source_title'=>'Family <b>cookbook</b>', 'source_author'=>'A & B',
    'source_url'=>"https://example.com/?author=O'Reilly&x=1",
    'source_reference'=>'Also www.example.org. <img src=x onerror=alert(1)>',
];
$page=recipeSharePage($recipe);
$dom=new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($page);
$xpath=new DOMXPath($dom);
$links=$xpath->query('//p[@class="source"]/a');
checkSource($links->length===2,'Individual page source links missing');
checkSource($links->item(0)->getAttribute('href')===$recipe['source_url'],'URL changed while escaping');
checkSource($links->item(0)->textContent===$recipe['source_url'],'URL display changed');
checkSource(str_contains($page,'O&#039;Reilly&amp;x=1'),'Href not HTML escaped');
checkSource(str_contains($page,'Family &lt;b&gt;cookbook&lt;/b&gt;') && str_contains($page,'A &amp; B'),'Source labels not escaped');
checkSource($xpath->query('//p[@class="source"]//img|//p[@class="source"]//script|//p[@class="source"]//b')->length===0,'Source HTML injection');
foreach (['source_title','source_author','source_url','source_reference'] as $field) {
    $variant=recipeSharePage(['title'=>'Fixture',$field=>'example.com']);
    checkSource(str_contains($variant,'href="https://example.com"'),'Bare domain not linked in '.$field);
}
checkSource(str_contains(recipeSharePage(['title'=>'Fixture','source_url'=>'javascript:alert(1)']),'<p class="source">javascript:alert(1)</p>'),'Unsafe source not preserved as plain text');
checkSource(!str_contains(recipeSharePage(['title'=>'Fixture']),'<h2>Source</h2>'),'Missing source created a section');
if (isset($argv[1])) file_put_contents($argv[1],$page);
echo "Source links: $checks checks passed.\n";
