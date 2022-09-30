/**
 * yform integration for Collaborate
 */
class CollaboratePluginYformBackend extends CollaboratePlugin {
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
     * page switch > check if yform and proceed with checking locking
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
     * yform > proceed with checking locking
     * @param data
     */
    onMessage(data) {
        // details page
        if($(".yform > form[id^='data_edit-']").length) {
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
            if(typeof(userData.pages[p].table_name) == "string" && typeof(userData.pages[p].data_id) == "string") {
                //userLocations[p] = .get(0).outerHTML;
                let $elem = $('<span>' + userLocations[p] + '</span>');

                if(!$elem.find(".title span.as-warning.id").length) {
                    $elem.find(".title").html($elem.find(".title").html() + ' <span class="as-warning id"><b>[ID: '+ userData.pages[p].data_id +']</b></span>');
                    userLocations[p] = $elem.get(0).innerHTML;
                }
            }
        }

        return userLocations;
    }

    /**
     * check yform tables overview for need to lock some rows
     */
    sendCurrentPosition(page) {
        if(typeof(page) != "object" || page.join("/") != "yform/manager/data_edit" ) {
            return false;
        }

        let payload = {
            page: {
                path: rex.page,
                title: document.title.replace(/\s*·.+/, ''),
                h1: $(".rex-page-header .page-header > h1")[0].innerText.replace(/[.\s\w\d]*(Tabelle:.+)/, '$1'),
                plugin: this.name, // with very basic user permissions pages are not communicated to those >
                                  // "plugin" flag marks a special case that notifies the handler to communicate such pages no matter of permission
            }
        };

        // merge edit page data
        if(typeof(rex.collaborate_plugin_yform_requests) != "undefined" && !$("form > table.table").length) {
            payload.page = {...payload.page, ...rex.collaborate_plugin_yform_requests}
            // payload.page.created = (new Date()).getTime();
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
            $("table.table[class*='yform-table-'] tbody tr.collaborate-locked").addClass("collaborate-maybe-unlock");

            for (const [user, userdata] of Object.entries(data)) {
                if(typeof(userdata.pages) != "object") {
                    continue;
                }

                userdata.pages.forEach(function (pagedata) {
                    // check if other user visits current table and blocks some data
                    if(typeof(pagedata) == "undefined" || typeof(pagedata.data_id) == "undefined") {
                        return;
                    }

                    if($("table.yform-table-"+ pagedata.table_name).length) {
                        let tableRows = $("table.yform-table-"+ pagedata.table_name).find("tbody tr");

                        // block rows
                        tableRows.each(function(idx, tr) {
                            // hit!
                            if(parseInt($(tr).children("td:nth-child(2)").text()) == parseInt(pagedata.data_id)) {
                                if($(tr).hasClass("collaborate-locked")) {
                                    $(tr).removeClass("collaborate-maybe-unlock");
                                } else {
                                    $(tr).addClass("collaborate-locked");

                                    let $lockInfo = $(
                                        '<div class="collaborate-lock-info">' +
                                            '<span class="user">'+
                                                '<span class="intro">'+ CollaborateI18N.dataset +
                                                    '<span class="value">[' +
                                                        'ID: '+ pagedata.data_id +
                                                        (typeof(pagedata.name) != "undefined" ? ' | Name: '+ pagedata.name : '') +
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
                                    let $appendTD = $(tr).children("td:first-child");
                                    $appendTD.addClass("collaborate-lock-cell");
                                    $lockInfo.appendTo($appendTD);
                                }
                            }
                        });
                    }
                });
            }

            // clean up delayed to avoid breaks when "übernehmen" is used to save
            if(_self.cleanuptimer != null) {
                clearInterval(_self.cleanuptimer);
                _self.cleanuptimer = null;
            }

            _self.cleanuptimer = setTimeout(function() {
                $("table.table[class*='yform-table-'] tbody tr.collaborate-locked.collaborate-maybe-unlock .collaborate-lock-info").remove();
                $("table.table[class*='yform-table-'] tbody tr.collaborate-locked.collaborate-maybe-unlock td.collaborate-lock-cell").removeClass("collaborate-lock-cell");
                $("table.table[class*='yform-table-'] tbody tr.collaborate-locked.collaborate-maybe-unlock").removeClass("collaborate-locked collaborate-maybe-unlock");
            }, _self.cleanupdelay);
        }
    }

    /**
     * check if another user already opened current dataset
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
                    // check if other user visits current table and blocks some data
                    if (typeof (pagedata) == "undefined" || typeof (pagedata.data_id) == "undefined" || typeof (pagedata.table_name) == "undefined") {
                        return;
                    }

                    // check if we are in same table
                    if($("form#data_edit-"+ pagedata.table_name).length &&
                       rex.collaborate_plugin_yform_requests.table_name == pagedata.table_name &&
                       rex.collaborate_plugin_yform_requests.data_id == pagedata.data_id
                    ) {
                        foundLock = true;
                        let form = $("form#data_edit-"+ pagedata.table_name);

                        if(!form.parent(".yform").hasClass("collaborate-lock-edit")) {
                            form.parent(".yform").addClass("collaborate-lock-edit");

                            // add locked info
                            let $blockInfo = $(
                                '<div class="collaborate-lock-edit-info">' +
                                    '<div class="message">' + CollaborateI18N.activity_blocked.replace("%s", pagedata.data_id) + '</div>' +
                                    '<div class="user">' + (typeof(userdata.username) != "undefined" ? userdata.username : '1 '+ CollaborateI18N.user) +'</div>' +
                                    '<span class="since">' +
                                        '<span class="intro">' + CollaborateI18N.since + '</span>' +
                                        '<span class="value">00:00</span>' +
                                    '</span>' +
                                    '<a href="'+ location.origin + '/redaxo/index.php?page=yform/manager/data_edit&table_name=' + rex.collaborate_plugin_yform_requests.table_name + '" class="back" />' +
                                        CollaborateI18N.activity_blocked_back +
                                    '</a>' +
                                '</div>'
                            );

                            $blockInfo.appendTo(form.parent(".yform"));
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

        // reset ... using timeout to give editiong user some time for page reloads that end up in edit mode again
        try {
            clearTimeout(_self.freedetailstimer);
            _self.freedetailstimer = null;
        } catch(e) {}

        if(!foundLock) {
            _self.freedetailstimer = setTimeout(function() {
                if($(".yform.collaborate-lock-edit .collaborate-lock-edit-info").length) {
                    $(".yform.collaborate-lock-edit .collaborate-lock-edit-info").remove();
                    $(".yform.collaborate-lock-edit").removeClass("collaborate-lock-edit");

                    // resend
                    _self.sendCurrentPosition(['yform','manager','data_edit']);

                    return false;
                }
            }, _self.freedetaildelay);
        }
    }
}

/**
 * on load (including pjax)
 */
$(document).on('rex:ready', function (e, container) {
    if(typeof(window.collaborate) == "undefined") {
        return;
    }

    if(!window.collaborate.pluginRegistered("yform")) {
        window.collaborate.registerPlugin(new CollaboratePluginYformBackend("yform"));
    }
});