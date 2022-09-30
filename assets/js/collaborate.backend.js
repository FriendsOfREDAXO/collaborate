/**
 * on load (including pjax)
 */
$(document).on('rex:ready', function (e, container) {
    // init collaborate
    if(window.collaborate) {
        return;
    }

    window.collaborate = new Collaborate();

    // init bootstrap tooltips in packages overview
    $('.collaborate [data-toggle="tooltip"]').tooltip({
        html: true,
        delay: 1000
    });
});