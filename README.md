n-gram plagarism detector ("Garry")
===================================

**Garry** is a n-gram plagarism detector that takes into account core topics from source articles. In the future it is also going to support copying of quotes. Most of the code is there to support this functionality.

Garry is brought to you with the support of the following libraries, without whom this functionality would not be possible:
* [Tim Groeneveld](http://timg.ws)'s [CleanHTML](https://github.com/timgws/CleanHTML) library
* [HTML Purifier](http://htmlpurifier.org/)
* [Term Extraction](http://fivefilters.org/term-extraction/)

The idea is to have this exposed as a simple web service where the source of content is being compared/checked against and the new content based on that source content as a reference. If a particular threshold is met (which is specified by your application) then you can reject the content.

Input
---
```
{
    "source": "This is a document. Tom is the Director of Being Awesome at Microsoft Fireworks. Tom said that it would be an ideal thing. Being Awesome is a hard job, said Barry.",
    "article": "This is a sermon. It has totally not been copied by someone called Tim Grogenhoegan. Being Awesome at Microsoft Fireworks is a hard job. Microsoft Fireworks director disagrees, saying that it is easier to have a document that is a indirect copy of anything that Tom did at work",
    "ngram": 3
}
```

Output
--
```
{
    "matches": 0,
    "score": 9,
    "check_count": 47,
    "most_in_row": 3,
    "percentage": 19.1489361702,
    "error": false,
    "excluded_words": ["Microsoft Fireworks"]
}
```

Because "Microsoft Fireworks" was detected as being a topic that was being talked about in the source document, it was rejected as being a phrase that would count towards being copied in the origional content. This makes Garry good for checking  against technical articles (such as scientific articles, patents and the like) because important topics that would otherwise be included as being copies are excluded from otherwise coming up as matches.

Note that there are two algorithms that are used (plain ngrams, and ngrams in a row). When using ngrams-in-a-row (the score is increased as more content is seen in a row from the difference of the two documents) matches will be 0.

When writing applications, you should check against ``score`` (total matches), ``check_count`` (how many matches could be found from the source... not necessarily just words), ``most_in_row`` (which still works with plain ngrams) and ``percentage``.

Always check that ``error`` is false from the JSON return. If it is ``true``, there will be a ``message`` key that shows the error.

``excluded_words`` will be a list of all the main topics.

You are able to send HTML as either the source or article, they both will be stripped of all the HTML. Accents and other UTF-8 characters that could be seen as being the same letter are automatically replaced.
