/**
 * initiate collaborate
 */
window.addEventListener('load', (event) => {
    // init collaborate
    if (window.collaborate) {
        return;
    }

    window.collaborate = new Collaborate();
});