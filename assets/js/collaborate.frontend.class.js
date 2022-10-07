/**
 * Collaborate
 */
class Collaborate {
    websocket = null;

    // vars
    debug = true;
    page = null;
    userid = null;
    tabid = null;
    plugins = [];

    // storing latest received status from server
    status = {};

    // further constants

    /**
     * init / constructor
     * @param config custom settings
     */
    constructor(config = {}) {
        // check if initialised
        if($("html").hasClass("collaborate-init") || typeof(collaborate_userid) == "undefined") {
            return;
        }

        $("html").addClass("collaborate-init")
        let _self = this;

        $(window).bind("beforeunload.collaborate", function (e) {
            // send close
            _self.send('PAGE_CLOSE');
            // suppress prompt
            return undefined;
        });

        this.userid = collaborate_userid;

        // set random tab id since german TTDSG does not allow consent-free access to local/session storage!
        this.tabid = Math.floor(Math.random() * 10000000);
        this.connect();
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

        this.websocket = new WebSocket(basePath);

        // set event handlers
        _self.websocket.onopen = function(e) {
            if(_self.debug) console.info("collaborate: connection established!");

            // clear intervals
            if(_self.retrytimer != null) {
                clearInterval(_self.retrytimer);
                _self.retrytimer = null;
            }
        };

        _self.websocket.onerror = function(e) {
            if(_self.debug) console.log(e);

            for (let plugin of _self.plugins) {
                plugin.onError(e);
            }
        };

        _self.websocket.onmessage = function(e) {
            //if(_self.debug) console.log(e.data);

            // further handling has to be done by plugins
            for (let plugin of _self.plugins) {
                plugin.onMessage(e);
            }
        };

        _self.websocket.onclose = function(e) {
            if(_self.debug) console.log(e);

            for (let plugin of _self.plugins) {
                plugin.onBeforeClose(e);
            }

            _self.statuscode = e.code;

            if (e.code == 3001) {
                console.log('collaborate: connection closed gracefully.');
            } else {
                console.log('collaborate: connection closed unwantedly.');
            }

            _self.websocket = null;

            for (let plugin of _self.plugins) {
                plugin.onClose(e);
            }
        };
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
     * send to websocket connection
     *
     * @param type
     * @param data
     * @param delay
     * @param callback
     */
    send(type = "", data = {}, delay = 0, callback = function(){}) {
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
        data.userid = _self.userid;
        data.tabid = _self.tabid;

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
        this.plugins.push(plugin);
    }

    /**
     * on page load
     */
    onPageLoad(event) {
        if(this.debug) console.log("collaborate: sending current page: "+ location.href);
        let returnOfPlugins = true;

        for (let plugin of this.plugins) {
            returnOfPlugins &&= plugin.onPageLoad();
        };

        if(returnOfPlugins) {
            let payload = {
                page: {
                    host: location.host,
                    pathname: location.pathname,
                    search: location.search,
                    hash: location.hash,
                    title: document.title,
                },
            };

            // send page if not interrupted by plugins
            this.send('VIEW', payload);
        }
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
    onError(event) {}
    onBeforeClose(event) {}
    onClose(event) {}
    onPageLoad(event) {}
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