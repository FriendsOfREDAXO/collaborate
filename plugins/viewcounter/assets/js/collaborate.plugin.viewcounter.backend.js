/**
 * article view counter integration for Collaborate
 */
class CollaboratePluginViewcounterBackend extends CollaboratePlugin {
    /**
     * init / constructor
     */
    constructor(name) {
        super(name);
        this.debug = true;
    }

    /**
     * structure > content edit mode > proceed with checking locking
     * @param data
     */
    onMessage(message) {
        if(!$("body#rex-page-structure").length) {
            return false;
        }

        if(typeof(message.data) == "string") {
            let data = JSON.parse(message.data);

            if(typeof(data.viewcount) == "undefined") {
                return;
            }

            data = data.viewcount;
            let $rows = $("table.table tbody tr.rex-status");

            $rows.each(function(idx) {
                let $idCol = $(this).children("td.rex-table-id");
                let entityId = parseInt($idCol[0].innerText);
                let $viewcounter = $idCol.next().children(".collaborate-viewcounter");

                // create viewcounter wrapper
                if(!$viewcounter.length) {
                    $viewcounter = $('<span class="collaborate-viewcounter"><span class="this"></span><span class="children"></span></span>');
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
    }

    /**
     * check if another user already opened current article for edit
     */
    checkBlockedDetailsPage(message) {
        let _self = this;
        let foundLock = false;
        console.log(message);

        if(typeof(message.data) == "string") {
            let data = JSON.parse(message.data);

            for (const [user, userdata] of Object.entries(data)) {
                if(typeof(userdata.pages) != "object") {
                    continue;
                }

                userdata.pages.forEach(function (pagedata) {
                    // check if other user visits current article
                    if (typeof (pagedata) == "undefined" || typeof (pagedata.article_id) == "undefined" || typeof (pagedata.category_id) == "undefined") {
                        return;
                    }

                    // check if we are editing same article
                    if($("#rex-page-content-edit").length &&
                       rex.collaborate_plugin_structure_requests.article_id == pagedata.article_id &&
                       rex.collaborate_plugin_structure_requests.category_id == pagedata.category_id
                    ) {
                        foundLock = true;
                        let $editArea = $("section.rex-main-frame");
                        let $langSwitch = $(".rex-nav-btn.rex-nav-language");

                        if(!$editArea.hasClass("collaborate-lock-edit")) {
                            $editArea.addClass("collaborate-lock-edit");
                            $langSwitch.addClass("collaborate-lock-edit");

                            // add locked info
                            let $blockInfo = $(
                                '<div class="collaborate-lock-edit-info">' +
                                    '<div class="message">' + CollaborateI18N.activity_blocked.replace("%s", pagedata.article_id) + '</div>' +
                                    '<div class="user">' + (typeof(userdata.username) != "undefined" ? userdata.username : '1 '+ CollaborateI18N.user) +'</div>' +
                                    '<span class="since">' +
                                        '<span class="intro">' + CollaborateI18N.since + '</span>' +
                                        '<span class="value">00:00</span>' +
                                    '</span>' +
                                    '<a href="'+ location.origin + '/redaxo/index.php?page=structure&category_id=' + rex.collaborate_plugin_structure_requests.category_id + '" class="back" />' +
                                        CollaborateI18N.activity_blocked_back +
                                    '</a>' +
                                '</div>'
                            );

                            $blockInfo.appendTo($editArea);
                            $blockInfo.focus();

                            // set timer
                            let created = (Number.isInteger(pagedata.created) ? pagedata.created : pagedata.created * 1000);
                            let diff = Math.round((Date.now() - created) / 1000);

                            if(collaborateTimePad(parseInt(diff / 60)) < 60) {
                                let timer = setInterval(function(){
                                    // cleared element?
                                    if(!$blockInfo.length) {
                                        clearInterval(timer);
                                        timer = null;
                                    }

                                    diff = Math.round((Date.now() - created) / 1000);

                                    if(collaborateTimePad(parseInt(diff / 60)) < 60) {
                                        $blockInfo.find(".since .value").html(
                                            collaborateTimePad(parseInt(diff / 60)) +
                                            ":"+
                                            collaborateTimePad(diff % 60)
                                        );
                                    } else {
                                        clearInterval(timer);
                                        timer = null;
                                        $blockInfo.find(".since .value").html(
                                            new Date(created).toLocaleDateString('de-DE', _self.parent.headerdateoptions)
                                        )
                                    }
                                }, 1000);
                            } else {
                                $blockInfo.find(".since .value").html(
                                    new Date(created).toLocaleDateString('de-DE', _self.parent.headerdateoptions)
                                );
                            }

                            return true;
                        }
                    }
                });
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