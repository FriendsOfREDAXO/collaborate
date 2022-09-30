/**
 * structure integration for Collaborate
 */
class CollaboratePluginStructureBackend extends CollaboratePlugin {
    cleanuptimer = null;
    cleanupdelay = 1000 * 3;
    freedetailstimer = null;
    freedetaildelay = 1000 * 2;

    /**
     * init / constructor
     */
    constructor(name) {
        super(name);
        this.debug = true;
    }

    /**
     * page switch > check if structure and proceed with checking locking
     * @param page array
     * @param pageChanged boolean
     */
    onPageLoad(page, pageChanged = false) {
        let _self = this;
        let sendcp = this.sendCurrentPosition(page);

        // overview
        if($("form > table.table").length) {
            this.checkBlockedAreas(this.parent.status);
        }

        // overwrite page handling of parent class by returning false
        return !sendcp;
    }

    /**
     * structure > content edit mode > proceed with checking locking
     * @param data
     */
    onMessage(data) {
        // details page
        if($("#rex-page-content-edit").length) {
            // if already blocked by another user > block panel, show infos and leave
            if(this.checkBlockedDetailsPage(data)) {
                return false;
            }
        }

        this.checkBlockedAreas(data);
    }

    /**
     * manipulating user details in popup box (for users with high permissions) > add edited ID, if set
     * @param data
     * @return pageDetails
     */
    onPopupUserDetails(userData, userLocations) {
        for(let p=0 ; p<userData.pages.length ; p++) {
            if(typeof(userData.pages[p].article_id) == "string" && typeof(userData.pages[p].category_id) == "string") {
                //userLocations[p] = .get(0).outerHTML;
                let $elem = $('<span>' + userLocations[p] + '</span>');

                if(!$elem.find(".title span.as-warning.id").length) {
                    $elem.find(".title").html($elem.find(".title").html() + ' <span class="as-warning id"><b>[ID: '+ userData.pages[p].article_id +']</b></span>');
                    userLocations[p] = $elem.get(0).innerHTML;
                }
            }
        }

        return userLocations;
    }

    /**
     * check structure tables overview for need to lock some rows
     */
    sendCurrentPosition(page) {
        if(typeof(page) != "object" || !(page.join("/") == "content/edit" || page.join("/") == "content/functions")) {
            return false;
        }

        let h1 = $(".rex-page-header .page-header > h1").clone();
        h1.find("#rex-quicknavigation-structure").remove();

        let payload = {
            page: {
                path: rex.page,
                title: document.title.replace(/\s*·.+/, ''),
                h1: h1[0].innerText,
                plugin: this.name
            }
        };

        // merge edit page data
        if(typeof(rex.collaborate_plugin_structure_requests) != "undefined" && !$("form > table.table").length) {
            payload.page = {...payload.page, ...rex.collaborate_plugin_structure_requests}
        }

        // send page if not interrupted by plugins
        this.parent.send('PAGE', payload);
        return true;
    }

    /**
     * checking for blocked areas
     */
    checkBlockedAreas(message) {
        let _self = this;

        if(typeof(message.data) == "string") {
            let data = JSON.parse(message.data);

            if(typeof(data.status) == "undefined") {
                return;
            }

            data = data.status;

            // mark locked rows to maybe unlock at the end
            $("table.table tbody tr.collaborate-locked").addClass("collaborate-maybe-unlock");

            for (const [user, userdata] of Object.entries(data)) {
                if(typeof(userdata.pages) != "object") {
                    continue;
                }

                userdata.pages.forEach(function (pagedata) {
                    // check if other user visits current table and blocks some data
                    if(typeof(pagedata) == "undefined" || typeof(pagedata.article_id) == "undefined") {
                        return;
                    }

                    if($("table.table tbody tr[data-article-id='"+ pagedata.article_id +"']").length) {
                        let $tr = $("table.table tbody tr[data-article-id='"+ pagedata.article_id +"']");

                        if($tr.hasClass("collaborate-locked")) {
                           $tr.removeClass("collaborate-maybe-unlock");
                        } else {
                            $tr.addClass("collaborate-locked");

                            let $lockInfo = $(
                                '<div class="collaborate-lock-info">' +
                                    '<span class="user">'+
                                        '<span class="intro">'+ CollaborateI18N.plugin_structure.article +
                                            '<span class="value">[' +
                                                'ID: '+ pagedata.article_id +
                                                (typeof(pagedata.name) != "undefined" ? ' | '+ pagedata.name : '') +
                                            ']</span>'+ CollaborateI18N.activity_edit +
                                        '</span>'+
                                        '<span class="value">'+ (typeof(userdata.username) != "undefined" ? userdata.username : '1 '+ CollaborateI18N.user) +'</span>' +
                                    '<span class="since">' +
                                        '<span class="intro">'+ CollaborateI18N.since +'</span>' +
                                        '<span class="value">00:00</span>' +
                                    '</span>' +
                                '</div>'
                            );

                            // set timer
                            let created = (Number.isInteger(pagedata.created) ? pagedata.created : pagedata.created * 1000);
                            let diff = Math.round((Date.now() - created) / 1000);

                            if(collaborateTimePad(parseInt(diff / 60)) < 60) {
                                let timer = setInterval(function(){
                                    // cleared element?
                                    if(!$lockInfo.length) {
                                        clearInterval(timer);
                                        timer = null;
                                    }

                                    diff = Math.round((Date.now() - created) / 1000);

                                    if(collaborateTimePad(parseInt(diff / 60)) < 60) {
                                        $lockInfo.find(".since .value").html(
                                            collaborateTimePad(parseInt(diff / 60)) +
                                            ":"+
                                            collaborateTimePad(diff % 60)
                                        );
                                    } else {
                                        clearInterval(timer);
                                        timer = null;
                                        $lockInfo.find(".since .value").html(
                                            new Date(created).toLocaleDateString('de-DE', _self.parent.headerdateoptions)
                                        )
                                    }
                                }, 1000);
                            } else {
                                $lockInfo.find(".since .value").html(
                                    new Date(created).toLocaleDateString('de-DE', _self.parent.headerdateoptions)
                                );
                            }

                            // set locked info
                            let $appendTD = $tr.children("td:first-child");
                            $appendTD.addClass("collaborate-lock-cell");
                            $lockInfo.appendTo($appendTD);
                        }
                    }
                });
            }

            // clean up delayed to avoid breaks when "übernehmen" is used to save
            if(_self.cleanuptimer != null) {
                clearInterval(_self.cleanuptimer);
                _self.cleanuptimer = null;
            }

            _self.cleanuptimer = setTimeout(function() {
                $("body#rex-page-structure section.rex-page-section table.table tbody tr.collaborate-locked.collaborate-maybe-unlock .collaborate-lock-info").remove();
                $("body#rex-page-structure section.rex-page-section table.table tbody tr.collaborate-locked.collaborate-maybe-unlock td.collaborate-lock-cell").removeClass("collaborate-lock-cell");
                $("body#rex-page-structure section.rex-page-section table.table tbody tr.collaborate-locked.collaborate-maybe-unlock").removeClass("collaborate-locked collaborate-maybe-unlock");
            }, _self.cleanupdelay);
        }
    }

    /**
     * check if another user already opened current article for edit
     */
    checkBlockedDetailsPage(message) {
        let _self = this;
        let foundLock = false;

        if(typeof(message.data) == "string") {
            let data = JSON.parse(message.data);

            if(typeof(data.status) == "undefined") {
                return;
            }

            data = data.status;

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

        // reset ... using timeout to give editing user some time for page reloads that end up in edit mode again
        try {
            clearTimeout(_self.freedetailstimer);
            _self.freedetailstimer = null;
        } catch(e) {}

        if(!foundLock) {
            _self.freedetailstimer = setTimeout(function() {
                if($("section.rex-main-frame.collaborate-lock-edit .collaborate-lock-edit-info").length) {
                    $("section.rex-main-frame.collaborate-lock-edit .collaborate-lock-edit-info").remove();
                    $("section.rex-main-frame.collaborate-lock-edit").removeClass("collaborate-lock-edit");
                    $(".rex-nav-btn.rex-nav-language").removeClass("collaborate-lock-edit");

                    // resend
                    _self.sendCurrentPosition(rex.page.split("/"));
                }
            }, _self.freedetaildelay);
        }
    }
}

/**
 * on load (including pjax)
 */
collaborate_structure_clang = null;
collaborate_structure_category = null;

$(document).on('rex:ready', function (e, container) {
    if(typeof(window.collaborate) == "undefined") {
        return;
    }

    let clang = parseInt(location.search.replace(/.+clang=(\d+)/, '$1'));
    let category = parseInt(location.search.replace(/.+category_id=(\d+)/, '$1'));

    if(!window.collaborate.pluginRegistered("structure")) {
        window.collaborate.registerPlugin(new CollaboratePluginStructureBackend("structure"));
    } else {
        window.collaborate.onPageLoad(true);
    }

    collaborate_structure_clang = !isNaN(clang) ? clang : rex.collaborate_clang;
    collaborate_structure_category = !isNaN(category) ? category : rex.collaborate_category;
});