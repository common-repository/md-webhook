(function () {
    var $ = jQuery;
    function run() {
        $(".md-webhook-content").each(function (index, ele) {
            var rawHtml = $(ele).html();
            rawHtml = rawHtml.replace(/([\s\S]*)<!---md-webhook--->/, "$1");
            rawHtml = rawHtml.replace(/&gt;/g, ">");
            rawHtml = rawHtml.replace(/&lt;/g, "<");
            rawHtml = rawHtml.trim();
            md = new markdownit({
                linkify: true,
                html: true,
                breaks: true,
                highlight: function (str, lang) {
                    if (lang && hljs.getLanguage(lang)) {
                        try {
                            return hljs.highlight(lang, str).value;
                        } catch (__) { }
                    }
                    return '';
                }
            });
            var html = md.render(rawHtml);
            $(ele).html(html);
            $(ele).css({opacity:1});
        });

        var $cloudLinks = $(".tag-cloud-link");
        $cloudLinks.sort(function (a, b) {
            var asize = $(a).css("font-size");
            var bsize = $(b).css("font-size");
            return asize > bsize ? -1 : 1
        })
        $cloudLinks.detach().appendTo('.tagcloud');
    }

    run();

})()
