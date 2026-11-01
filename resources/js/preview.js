// Runs on Statamic live preview pages opened outside the control panel
// (on a phone, in another browser). Listens on the preview token's public
// channel with the Pusher protocol and reloads the page, keeping the scroll
// position, whenever the entry changes in the control panel.
window.HibiraStatamicPreview = function (config) {
    if (window.self !== window.top || window.HibiraStatamicPreview.started) return;
    window.HibiraStatamicPreview.started = true;

    var key = 'hibira-statamic-scroll:' + location.pathname;
    try {
        var saved = sessionStorage.getItem(key);
        if (saved !== null) {
            sessionStorage.removeItem(key);
            var restore = function () { window.scrollTo(0, Number(saved) || 0); };
            if (document.readyState === 'complete') restore();
            else window.addEventListener('load', restore);
        }
    } catch (e) {}

    var delay = 1000;
    var timer;

    function reload() {
        try { sessionStorage.setItem(key, String(window.scrollY)); } catch (e) {}
        location.reload();
    }

    function connect() {
        var socket = new WebSocket(config.url);

        socket.onmessage = function (message) {
            var data;
            try { data = JSON.parse(message.data); } catch (e) { return; }

            if (data.event === 'pusher:connection_established') {
                delay = 1000;
                socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: config.channel } }));
            } else if (data.event === 'pusher:ping') {
                socket.send(JSON.stringify({ event: 'pusher:pong', data: {} }));
            } else if (data.event === 'refresh' && data.channel === config.channel) {
                clearTimeout(timer);
                timer = setTimeout(reload, 250);
            }
        };

        socket.onclose = function () {
            setTimeout(connect, delay);
            delay = Math.min(delay * 2, 30000);
        };
    }

    connect();
};
