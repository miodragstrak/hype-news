<?php

$news =
json_decode(
file_get_contents(
'cache/news.json'
),
true
);

?>

<link
rel="stylesheet"
href="style.css"
>

<section
class="news-section"
>

<div
class="brand-mark"
>

<img
src="assets/hype-logo.png"
alt="Hype"
>

</div>

<div
class="news-header"
>

<span>
›
</span>

<h2>
VESTI
</h2>

</div>

<div
class="banner"
>

<?php foreach(
$news
as
$item
):

$date='';

if(
!empty(
$item[
'published_at'
]
)
){

$date=
date(
'd.m.Y.',
strtotime(
$item[
'published_at'
]
)
);

}

?>

<a

class="card"

target="_blank"

rel="noopener noreferrer"

href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>"

>

<div
class="thumb"
>

<img

src="<?= $item['image'] ?>"

alt="<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"

>

</div>

<div
class="content"
>

<h3>

<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>

</h3>

<div
class="meta"
>

<span>

🗓

<?= $date ?>

</span>

<span>

<?= $item['source'] ?>

</span>

</div>

</div>

</a>

<?php endforeach; ?>

</div>

</section>
