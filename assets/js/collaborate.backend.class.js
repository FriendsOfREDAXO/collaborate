/**
 * Collaborate
 */
class Collaborate {
    websocket = null;

    // vars
    debug = true;
    page = null;
    tabid = null;
    plugins = [];

    // storing latest received status from server
    status = {};

    // server status timer
    statustimer = null;
    statuscode = null;
    servertime = null;
    retrytimer = null;
    retryinterval = 1000 * 60;

    // send keep alive every x msec
    pingtimer = null;
    pinginterval = 1000 * 60;

    // further constants
    tooltipSettings = {
        delay: {
            show: 500,
            hide: 0
        }
    };

    // header stuff
    header = null;
    headerdateoptions = {year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'};

    /**
     * init / constructor
     * @param config custom settings
     */
    constructor(config = {}) {
        // check if initialised
        if($("html").hasClass("collaborate-init")) {
            return;
        }

        $("html").addClass("collaborate-init")
        let _self = this;

        // store tab id on close
        $(window).bind("beforeunload.collaborate", function (e) {
            window.sessionStorage.tabid = _self.tabid;
            // send close
            _self.send('PAGE_CLOSE');
            // suppress prompt
            return undefined;
        });

        // set tab id
        if (typeof(window.sessionStorage.tabid) != "undefined" && parseInt(window.sessionStorage.tabid)) {
            _self.tabid = window.sessionStorage.tabid;
            window.sessionStorage.removeItem("tabid");
        } else {
            _self.tabid = Math.floor(Math.random() * 10000000);
        }

        // set page
        // this.onPageLoad();

        _self.header = $(".collaborate.header-box");
        _self.header = (!this.header.length ? null : this.header);

        // header specials
        if(_self.header != null) {
            // bind refresh button
            _self.header.find("button.refresh").click(function(e) {
                // no refresh needed if already online
                if(_self.websocket != null || _self.header.attr("data-status") == 'CONNECTING') {
                    return;
                }

                _self.connect();
            });

            // bind info box
            if(_self.header.hasClass("expandable")) {
                _self.header.find(".status").click(function(){
                    // prevent opening info panel if there is no user
                    if(Object.keys(_self.status) == 0) {
                        _self.header.removeClass("show-info-box");
                        return;
                    }

                    _self.header.toggleClass("show-info-box");
                });
            }
        }

        this.connect();
    }

    /**
     * wait for websocket to be open
     * @return {Promise}
     */
    waitForOpenSocket()  {
        let _self = this;

        return new Promise((resolve) => {
            if (_self.websocket.readyState !== _self.websocket.OPEN) {
                _self.websocket.addEventListener("open", _ => {
                    resolve();
                });
            } else {
                resolve();
            }
        });
    }

    /**
     * connect to server
     */
    connect() {
        if(this.debug) console.info("collaborate: connecting ...");

        let basePath = 'wss://'+ location.host + ':' + CollaborateSettings['client-port'] + '/' + CollaborateSettings.path;
        let _self = this;

        // re-init fix
        if(_self.websocket != null) {
            _self.websocket.close(3001);
        }

        if(this.header != null) {
            this.header.attr("data-status", 'CONNECTING');
        }

        this.websocket = new WebSocket(basePath);

        // set event handlers
        _self.websocket.onopen = function(e) {
            if(_self.debug) console.info("collaborate: connection established!");

            // clear intervals
            if(_self.retrytimer != null) {
                clearInterval(_self.retrytimer);
                _self.retrytimer = null;
            }

            // init ping
            _self.initPing();

            // refresh header
            _self.refreshHeader("ONLINE");

            // send login
            _self.send('LOGIN', {}, 0, function() {
                // provide current page
                _self.onPageLoad();
            });
        };

        _self.websocket.onerror = function(e) {
            if(_self.debug) console.log(e);

            for (let plugin of _self.plugins) {
                plugin.onError(e);
            }
        };

        _self.websocket.onmessage = function(e) {
            //if(_self.debug) console.log(e.data);

            for (let plugin of _self.plugins) {
                plugin.onMessage(e);
            }

            // storing latest transmitted data
            let status = JSON.parse(e.data);

            if(typeof(status) == 'object' && typeof(status.status) == 'object') {
                _self.status = status.status;

                // refresh header
                _self.refreshHeader(
                    localStorage.getItem('collaborate-status'),
                    status.status
                );
            }
        };

        _self.websocket.onclose = function(e) {
            if(_self.debug) console.log(e);

            _self.statuscode = e.code;

            if (e.code == 3001) {
                console.log('collaborate: connection closed gracefully.');
            } else {
                console.log('collaborate: connection closed unwantedly.');

                // retry constantly
                if( _self.retrytimer != null) {
                    clearInterval(_self.retrytimer);
                    _self.retrytimer = null;
                }

                _self.retrytimer = setInterval(function() {
                    _self.connect();
                }, _self.retryinterval);
            }

            // clear ping timer if online before
            if(_self.pingtimer != null) {
                clearInterval(_self.pingtimer);
                _self.pingtimer = null;
            }

            _self.websocket = null;
            _self.refreshHeader("OFFLINE");

            for (let plugin of _self.plugins) {
                plugin.onClose(e);
            }
        };

        // keep current page updated
        $(document).on("rex:ready", function() {
            // _self.onPageLoad()
        });
    }

    /**
     * send to websocket connection
     *
     * @param type
     * @param data
     * @param delay
     * @param callback
     * @param forceUpdate
     */
    send(type = "", data = {}, delay = 0, callback = function(){}, forceUpdate = false) {
        let _self = this;

        // do nothing if type is not set
        if(!type || type == "") {
            return;
        }

        // clear invalid data
        if(typeof(data) != 'object') {
            if(_self.debug) console.warn("collaborate: invalid data provided (not an object): "+ data);
            return;
        }

        // add base data
        data.type = type;
        data.user = rex.collaborate_login;
        data.userhash = rex.collaborate_login_hash;
        data.tabid = _self.tabid;
        // data.created = (new Date()).getTime();

        // tell the broadcast method to include initiating client
        if(forceUpdate) {
            data.forceupdate = true;
        }

        setTimeout(async function () {
            if (_self.websocket == null) {
                return;
            }

            // discard errors on send, since there is no reliable way now to force a resend or notice the user about an error
            try {
                if (_self.websocket.readyState !== _self.websocket.OPEN) {
                    try {
                        await _self.waitForOpenSocket();
                        _self.websocket.send(JSON.stringify(data));
                    } catch (err) {
                        console.error(err)
                    }
                } else {
                    _self.websocket.send(JSON.stringify(data))
                }

                // fire callback
                callback();
            } catch (e) {
                console.error(e);
            }
        }, delay);
    }

    /**
     * register plugin
     * @param plugin
     */
    registerPlugin(plugin) {
        if(this.pluginRegistered(plugin.getName())) {
            return false;
        }

        if(this.debug) console.log("collaborate: registered plugin: "+ plugin.getName());
        this.plugins.push(plugin);
        return true;
    }

    /**
     * check if plugin was registered before
     * @param plugin
     * @return {boolean}
     */
    pluginRegistered(pluginName) {
        for (let p of this.plugins) {
            // prevent double adding
            if(p.getName() == pluginName) {
                return true;
            }
        }

        return false;
    }

    /**
     * on page load
     * @param forceUpdate
     */
    onPageLoad(forceUpdate = false) {
        if(this.debug) console.log("collaborate: current page: "+ rex.page);

        let newPage = rex.page.split("/");
        let returnOfPlugins = true;

        for (let plugin of this.plugins) {
            returnOfPlugins &&= plugin.onPageLoad(newPage, newPage != this.page);
        };

        this.page = newPage;

        if(returnOfPlugins) {
            let payload = {
                page: {
                    path: rex.page,
                    title: document.title.replace(/\s*·.+/, ''),
                    h1: $(".rex-page-main header.rex-page-header h1").first().clone().find("#rex-quicknavigation-structure").remove().end().text()
                }
            };

            // send page if not interrupted by plugins
            this.send('PAGE', payload, 0, function(){}, forceUpdate);
        }
    }

    /**
     * refreshing header status
     * @param status
     */
    refreshHeader(status, userData = []) {
        if(this.header == null) {
            return;
        }

        if(this.statustimer != null) {
            clearInterval(this.statustimer);
            this.statustimer = null;
        }

        // get previous status
        let previousStatus = localStorage.getItem('collaborate-status');
        let statusTimestamp = localStorage.getItem('collaborate-status-timestamp');

        if(status != previousStatus || previousStatus == null) {
            statusTimestamp = (this.servertime == null ? Date.now() : this.servertime);
            localStorage.setItem('collaborate-status', status);
            localStorage.setItem('collaborate-status-timestamp', statusTimestamp);
        }

        this.header.attr("data-status", status);
        this.header.attr("data-start-time", parseInt(statusTimestamp));

        let _header = this.header;

        // set timer
        let diff = Math.round((Date.now() - parseInt(statusTimestamp)) / 1000);

        if(collaborateTimePad(parseInt(diff / 60)) < 60) {
            this.statustimer = setInterval(function(){
                diff = Math.round((Date.now() - parseInt(statusTimestamp)) / 1000);

                if(collaborateTimePad(parseInt(diff / 60)) < 60) {
                    _header.find(".sub-status .since .counter").html(
                        collaborateTimePad(parseInt(diff / 60)) +
                        ":"+
                        collaborateTimePad(diff % 60)
                    );
                } else {
                    clearInterval(this.statustimer);
                    this.statustimer = null;
                    _header.find(".sub-status .since .counter").html(new Date(parseInt(statusTimestamp)).toLocaleDateString('de-DE', this.headerdateoptions));
                }
            }, 1000);
        } else {
            _header.find(".sub-status .since .counter").html(new Date(parseInt(statusTimestamp)).toLocaleDateString('de-DE', this.headerdateoptions));
        }

        // set user count
        let previousUserCount = parseInt(this.header.find(".user-count .value").text());
        this.header.find(".user-count .value").text(Object.keys(userData).length);
        this.header.attr("data-users", Object.keys(userData).length);

        // stop button refresh animation
        this.header.find("button.refresh i").removeClass("fa-spin");

        // set user infos
        if(this.header.hasClass("expandable")) {
            // hide popup panel if there is no user left connected
            if(Object.keys(userData).length == 0) {
                this.header.removeClass("show-info-box");
            }

            // write user info line
            let $userInfo = this.header.find(".user-info ul");

            if($userInfo.length) {
                // clear
                $userInfo.html("");
                let $listElem;

                for (const [user, userdata] of Object.entries(userData)) {
                    if(typeof(userdata.username) == "undefined") {
                        continue;
                    }

                    $listElem = $('<li></li>');

                    // append username
                    $('<span class="username">'+ userdata.username +'</span>').appendTo($listElem);
                    $('<span class="loggedin-since">'+
                        CollaborateI18N.header_loggedin_since +' '+
                        new Date(parseInt(parseFloat(userdata.loginTimestamp) * 1000)).toLocaleDateString('de-DE', this.headerdateoptions) +
                    '</span>').appendTo($listElem);

                    // further details if perms match
                    if(typeof(userdata.pages) == "object" && (rex.collaborate_user_perms == 'ADMIN' || rex.collaborate_user_perms.includes('collaborate[user_locations]'))) {
                        let userLocations = [];

                        for(let p=0 ; p<userdata.pages.length ; p++) {
                            userLocations.push(
                                '<span class="index">'+ (p+1) +'</span>'+
                                '<span class="title">'+ userdata.pages[p].h1 +'</span>'+
                                '<span class="since">'+
                                    CollaborateI18N.since +' '+
                                    new Date(parseInt(parseFloat(userdata.pages[p].created) * 1000)).toLocaleDateString('de-DE', this.headerdateoptions) +
                                '</span>'
                            );
                        }

                        if(userLocations.length > 0) {
                            // event handler/callback for plugins
                            for (let plugin of this.plugins) {
                                userLocations = plugin.onPopupUserDetails(userdata, userLocations);
                            }

                            $('<div class="page-details"><span class="page">'+ userLocations.join('</span><span class="page">') +'</span></div>').appendTo($listElem);
                        }
                    }

                    $listElem.appendTo($userInfo);

                    // userdata.pages.forEach(function (pagedata) {
                    //     if(typeof(pagedata.data_id) == "undefined") {
                    //         return;
                    //     }
                    // });
                }
            }
        }
    }

    /**
     * ping every X seconds
     */
    initPing() {
        if(this.pingtimer != null) {
            clearInterval(this.pingtimer);
            this.pingtimer = null;
        }

        let _self = this;

        // send ping
        this.pingtimer = setInterval(function(){
            //if(_self.debug) console.log("collaborate: sending ping ["+new Date()+"] ...");
            _self.send('PING');
        }, this.pinginterval);
    }
}

/**
 * plugin interface
 */
class CollaboratePlugin {
    parent = 1;
    debug = false;
    name = null;

    /**
     * init / constructor
     */
    constructor(name) {
        if(typeof(window.collaborate) == "undefined") {
            return;
        }

        this.name = name;
        this.parent = window.collaborate;
    }

    getName() {
        return this.name;
    }

    /**
     * shortcut to send to parent
     * @param data
     * @param delay
     * @param callback
     */
    send(type, data, delay = 0, callback = function(){}) {
        this.parent.send(data, delay, callback);
    }

    onMessage(event) {}
    onPopupUserDetails(userdata, userLocations) { return userLocations; }
    onError(event) {}
    onClose(event) {}
    onPageLoad(page, pageChanged = false, forceUpdate = false) {
        return true;
    }
}

/**
 * leading zeros for collaborate header timer
 * @param val
 * @return {string}
 */
function collaborateTimePad(val) {
    let valString = val + "";

    if (valString.length < 2) {
        return "0" + valString;
    } else {
        return valString;
    }
}