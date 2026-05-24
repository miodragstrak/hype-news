async function loadNews() {

const urls = [

{
url:
"https://boronline.rs/wp-json/wp/v2/posts?_embed",

source:
"Bor",

limit:
2
},

{
url:
"https://zajecaronline.rs/wp-json/wp/v2/posts?_embed",

source:
"Zaječar",

limit:
2
}

];

const responses =
await Promise.all(

urls.map(
x=>
fetch(
x.url
)
)

);

const json =
await Promise.all(

responses.map(
r=>
r.json()
)

);

let news=[];

json.forEach(

(posts,index)=>{

news.push(

...posts

.slice(
0,
urls[index].limit
)

.map(
p=>({

title:
p.title.rendered,

url:
p.link,

image:

p
._embedded
?.["wp:featuredmedia"]
?.[0]
?.source_url

||

"",

published_at:
p.date,

source:
urls[index]
.source

})

)

);

}

);

render(
news
);

}

function render(
news
){

const root=
document
.getElementById(
"news"
);

root.innerHTML=

news.map(

n=>`

<a
class="card"
href="${n.url}"
target="_blank">

<img
src="${n.image}">

<h3>

${n.title}

</h3>

<div
class="meta">

${new Date(
n.published_at
)

.toLocaleDateString(
"sr-RS"
)}

</div>

<div
class="meta">

${n.source}

</div>

</a>

`

)

.join("");

}

loadNews();