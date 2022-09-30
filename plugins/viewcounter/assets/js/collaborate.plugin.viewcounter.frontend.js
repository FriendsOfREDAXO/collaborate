/**
 * viewcounter integration for Collaborate
 */
class CollaboratePluginViewcounterFrontend extends CollaboratePlugin {
    /**
     * init / constructor
     */
    constructor(name) {
        super(name);
        this.debug = true;
        this.onPageLoad(null);
    }

    /**
     * @param page array
     * @param pageChanged boolean
     */
    onPageLoad(page, pageChanged = false) {
        let _self = this;

        let payload = {
            page: {
                path: location,
                title: document.title,
                h1: $("h1").length ? $("h1")[0].innerText : null,
                plugin: this.name
            }
        };

        // send page if not interrupted by plugins
        this.parent.send('PAGEVIEW', payload);

        // overwrite page handling of parent class by returning false
        return false;
    }

    /**
     * we do not intend to give feedback to FE clients
     * @param data
     */
    onMessage(data) {
        return false;
    }
}

/**
 * on load page
 */
window.addEventListener('load', (event) => {
    if(typeof(window.collaborate) == "undefined") {
        return;
    }

    window.collaborate.registerPlugin(new CollaboratePluginViewcounterFrontend("viewcounter"));
});