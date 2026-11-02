/* ES5-only Samsung Tizen TV launcher for the Nowills PMS web portal. */
(function () {
  'use strict';

  var DEFAULT_SERVER = 'https://pms.nowills.com';
  var SERVER_KEY = 'nowills-tv-server-v1';
  var HOTEL_KEY = 'nowills-tv-hotel-v1';
  var serverInput = document.getElementById('server');
  var hotelInput = document.getElementById('hotel-code');
  var form = document.getElementById('connection-form');
  var notice = document.getElementById('notice');
  var configure = document.getElementById('configure');
  var startup = document.getElementById('startup');
  var changeButton = document.getElementById('change-settings');
  var redirectTimer = null;
  var current = null;

  function trim(value) { return String(value || '').replace(/^\s+|\s+$/g, ''); }
  function read(key) {
    try { return localStorage.getItem(key) || ''; } catch (e) { return ''; }
  }
  function save(key, value) {
    try { localStorage.setItem(key, value); } catch (e) { /* Continue for this launch if storage is restricted. */ }
  }
  function validServer(server) {
    return /^https:\/\/[a-z0-9.-]+(?::[0-9]{1,5})?(?:\/[a-z0-9._~/-]*)?$/i.test(server);
  }
  function validCode(code) { return /^[a-z0-9_-]{2,20}$/i.test(code); }
  function showForm(message) {
    if (redirectTimer !== null) { clearTimeout(redirectTimer); redirectTimer = null; }
    startup.hidden = true;
    configure.hidden = false;
    notice.textContent = message || '';
    if (current) {
      serverInput.value = current.server;
      hotelInput.value = current.code;
    }
    hotelInput.focus();
  }
  function openSetup(settings) {
    current = settings;
    startup.hidden = false;
    configure.hidden = true;
    changeButton.focus();
    redirectTimer = setTimeout(function () {
      var base = settings.server.replace(/\/+$/, '');
      var code = encodeURIComponent(settings.code);
      location.replace(base + '/tv/setup/' + code + '?launch=welcome');
    }, 3500);
  }

  serverInput.value = read(SERVER_KEY) || DEFAULT_SERVER;
  hotelInput.value = read(HOTEL_KEY);
  var savedServer = trim(read(SERVER_KEY));
  var savedCode = trim(read(HOTEL_KEY));
  if (validServer(savedServer) && validCode(savedCode)) {
    openSetup({ server: savedServer, code: savedCode });
  } else {
    hotelInput.focus();
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var server = trim(serverInput.value).replace(/\/+$/, '');
    var code = trim(hotelInput.value);
    if (!validServer(server)) {
      notice.textContent = 'Enter a valid HTTPS address, for example https://pms.nowills.com.';
      serverInput.focus();
      return;
    }
    if (!validCode(code)) {
      notice.textContent = 'Enter the hotel code shown in Nowills PMS IPTV settings.';
      hotelInput.focus();
      return;
    }
    save(SERVER_KEY, server);
    save(HOTEL_KEY, code);
    openSetup({ server: server, code: code });
  });

  changeButton.addEventListener('click', function () { showForm('Update the PMS server or hotel code, then continue.'); });
})();
