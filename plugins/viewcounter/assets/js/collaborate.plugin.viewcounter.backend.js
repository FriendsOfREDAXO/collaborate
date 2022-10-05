/**
 * article view counter integration for Collaborate
 */
class CollaboratePluginViewcounterBackend extends CollaboratePlugin {
    globalCounter = null;

    /**
     * init / constructor
     */
    constructor(name) {
        super(name);
        this.debug = true;

        if(rex.collaborate_perm_viewcounter_global) {
            let $structureNav = $("nav.rex-nav-main-navigation ul.rex-nav-main-list li#rex-navi-page-structure");

            if($structureNav.length) {
                this.globalCounter = $structureNav.children(".collaborate-global-viewcounter");

                // create viewcounter wrapper
                if(!this.globalCounter.length) {
                    this.globalCounter = $('<span class="collaborate-global-viewcounter" title="'+ CollaborateI18N.plugin_viewcounter.counter_all_tooltip +'"></span>');
                    this.globalCounter.appendTo($structureNav);
                }
            }
        }
    }

    /**
     * structure > content edit mode > proceed with checking locking
     * @param data
     */
    onMessage(message) {
        if(typeof(message.data) == "string") {
            let data = JSON.parse(message.data);

            if(typeof(data.viewcount) == "undefined") {
                return;
            }

            data = data.viewcount;

            if(typeof(data) == "object") {
                // structure tables > show bubbles
                if(rex.collaborate_perm_viewcounter_structure && $("body#rex-page-structure").length) {
                    let $rows = $("table.table tbody tr.rex-status");

                    $rows.each(function(idx) {
                        let $idCol = $(this).children("td.rex-table-id");
                        let entityId = parseInt($idCol[0].innerText);
                        let $viewcounter = $idCol.next().children(".collaborate-viewcounter");

                        // create viewcounter wrapper
                        if(!$viewcounter.length) {
                            $viewcounter = $(
                                '<span class="collaborate-viewcounter" title="'+ CollaborateI18N.plugin_viewcounter.counter_tooltip +'">' +
                                    '<span class="this"></span>' +
                                    '<span class="children"></span>' +
                                '</span>'
                            );
                            $viewcounter.appendTo($idCol.next());
                        }

                        if(typeof(data[entityId]) != "undefined") {
                            // categories
                            if(!this.hasAttribute("data-article-id")) {
                                if(data[entityId]["count_" + collaborate_viewcounter_clang] > 0 || data[entityId]["children_" + collaborate_viewcounter_clang] > 0) {
                                    $viewcounter.children(".this").text(data[entityId]["count_" + collaborate_viewcounter_clang]);
                                    $viewcounter.children(".children").text(data[entityId]["children_" + collaborate_viewcounter_clang]);
                                }
                            }
                            // articles
                            else if(data[entityId]["count_" + collaborate_viewcounter_clang] > 0) {
                                $viewcounter.children(".this").text(data[entityId]["count_" + collaborate_viewcounter_clang]);
                            }
                        } else {
                            $viewcounter.children().text("");
                        }
                    });
                }

                // global view counter
                if(rex.collaborate_perm_viewcounter_global && this.globalCounter != null) {
                    let globalCount = 0;

                    for(let articleId in data) {
                        for(let countParam in data[articleId]) {
                            if(countParam.indexOf("count_") == 0) {
                                globalCount += data[articleId][countParam];
                            }
                        }
                    }

                    this.globalCounter.text(globalCount == 0 ? '' : globalCount);
                }
            }
        }
    }
}

/**
 * on load (including pjax)
 */
collaborate_viewcounter_clang = null;
collaborate_viewcounter_category = null;

$(document).on('rex:ready', function (e, container) {
    if(typeof(window.collaborate) == "undefined") {
        return;
    }

    let clang = parseInt(location.search.replace(/.+clang=(\d+)/, '$1'));
    let category = parseInt(location.search.replace(/.+category_id=(\d+)/, '$1'));

    if(!window.collaborate.pluginRegistered("viewcounter")) {
        window.collaborate.registerPlugin(new CollaboratePluginViewcounterBackend("viewcounter"));
    } else {
        // no forceUpdate flag necessary since we hook into beforeMessage with plugin server script and send custom data
        window.collaborate.onPageLoad();
    }

    collaborate_viewcounter_clang = !isNaN(clang) ? clang : rex.collaborate_clang;
    collaborate_viewcounter_category = !isNaN(category) ? category : rex.collaborate_category;
});