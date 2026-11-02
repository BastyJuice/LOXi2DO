/* Local UI only. Configuration is still submitted through the existing guarded PHP forms. */
(function () {
    'use strict';
    var app=document.getElementById('loxi2do-app');
    if (!app) return;
    var dialog=document.getElementById('tablet-dialog'), opener=null;
    function closeDialog() {
        if (!dialog) return;
        if (typeof dialog.close==='function' && dialog.open) dialog.close();
        dialog.classList.remove('is-fallback-open'); dialog.removeAttribute('open');
        if (opener && document.contains(opener)) opener.focus();
    }
    function showDialog(button,initial) {
        if (!dialog) return;
        opener=button || document.activeElement;
        if (!initial) {
            var edit=button && button.hasAttribute('data-tablet-edit');
            document.getElementById('device-id').value=edit ? button.getAttribute('data-id') : '';
            document.getElementById('device-name').value=edit ? button.getAttribute('data-name') : '';
            document.getElementById('device-host').value=edit ? button.getAttribute('data-host') : '';
            var token=document.getElementById('device-token'); token.value=''; token.required=!edit;
            document.getElementById('tablet-dialog-title').textContent=edit ? 'Tablet-Zugang bearbeiten' : 'Tablet hinzufügen';
            document.getElementById('device-token-help').textContent=edit ? 'Leer lassen, um den gespeicherten Token beizubehalten.' : 'Den vorhandenen Token aus der LOXi2DO-App eintragen.';
            var error=dialog.querySelector('[role="alert"]'); if (error) error.hidden=true;
        }
        if (typeof dialog.showModal==='function') { if (!dialog.open) dialog.showModal(); }
        else { dialog.setAttribute('open',''); dialog.classList.add('is-fallback-open'); }
        document.getElementById('device-name').focus();
    }
    app.addEventListener('click',function (event) {
        var add=event.target.closest('[data-tablet-add]'), edit=event.target.closest('[data-tablet-edit]');
        if (add || edit) { event.preventDefault(); showDialog(add || edit,false); }
        if (event.target.closest('[data-dialog-close]')) { event.preventDefault(); closeDialog(); }
    });
    if (dialog) {
        dialog.addEventListener('cancel',function (event) { event.preventDefault(); closeDialog(); });
        dialog.addEventListener('keydown',function (event) {
            if (event.key==='Escape') { event.preventDefault(); closeDialog(); return; }
            if (event.key!=='Tab') return;
            var nodes=dialog.querySelectorAll('button:not([disabled]),input:not([type="hidden"]):not([disabled]),a[href],select,textarea');
            if (!nodes.length) return;
            var first=nodes[0], last=nodes[nodes.length-1];
            if (event.shiftKey && document.activeElement===first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement===last) { event.preventDefault(); first.focus(); }
        });
        if (dialog.getAttribute('data-auto-open')==='1') showDialog(null,true);
    }
    app.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit',function (event) { if (!window.confirm(form.getAttribute('data-confirm'))) event.preventDefault(); });
    });
    var tabletSearch=document.getElementById('tablet-search');
    if (tabletSearch) tabletSearch.addEventListener('input',function () {
        var term=tabletSearch.value.trim().toLocaleLowerCase();
        app.querySelectorAll('.tablet-list-item').forEach(function (item) { item.hidden=item.getAttribute('data-search').toLocaleLowerCase().indexOf(term)===-1; });
    });
    var settings=document.getElementById('settings-form'), dirty=false;
    if (settings) {
        var groups=Array.prototype.slice.call(settings.querySelectorAll('[data-settings-group]'));
        var menuButtons=Array.prototype.slice.call(settings.querySelectorAll('[data-settings-target]'));
        var activeGroup=document.getElementById('settings-active-group');
        var breadcrumb=document.getElementById('settings-breadcrumb-current');
        function activateGroup(group,remember) {
            if (!group) return;
            groups.forEach(function (candidate) {
                var selected=candidate===group;
                candidate.hidden=!selected;
                candidate.classList.toggle('is-active',selected);
            });
            menuButtons.forEach(function (button) {
                var selected=button.getAttribute('data-settings-target')===group.getAttribute('data-settings-group');
                button.setAttribute('aria-pressed',selected ? 'true' : 'false');
                button.classList.toggle('is-active',selected);
            });
            activeGroup.value=group.getAttribute('data-settings-group');
            breadcrumb.textContent=group.getAttribute('data-group-title');
            app.querySelectorAll('[data-settings-extra]').forEach(function (extra) {
                extra.hidden=extra.getAttribute('data-settings-extra')!==activeGroup.value;
            });
            if (remember && window.history && history.replaceState) {
                history.replaceState(null,'','#'+group.id);
            }
        }
        function groupFromHash() {
            return groups.find(function (group) { return '#'+group.id===window.location.hash; });
        }
        menuButtons.forEach(function (button) {
            button.addEventListener('click',function () {
                var target=groups.find(function (group) { return group.getAttribute('data-settings-group')===button.getAttribute('data-settings-target'); });
                activateGroup(target,true);
            });
        });
        window.addEventListener('hashchange',function () { var target=groupFromHash(); if (target) activateGroup(target,false); });
        // Native validation must reveal a required field before the browser focuses it.
        var validationGroup=null;
        settings.addEventListener('invalid',function (event) {
            var group=event.target.closest('[data-settings-group]');
            if (group && !validationGroup) {
                validationGroup=group; activateGroup(group,true);
                setTimeout(function () { validationGroup=null; },0);
            }
        },true);
        var serverGroup=groups.find(function (group) { return !group.hidden; }) || groups[0];
        // A posted validation error selects its section on the server; an old URL hash
        // must not hide that error. Other direct links may select a section normally.
        var invalidDraft=app.querySelector('[role="alert"]') && app.querySelector('[role="alert"]').textContent.trim()!=='';
        activateGroup(invalidDraft ? serverGroup : (groupFromHash() || serverGroup),false);
        var chargeControl=document.getElementById('setting-charge_control_enabled');
        var fireCare=document.getElementById('setting-battery_care_mode_enabled');
        function updateBatteryFields() {
            settings.querySelectorAll('[data-charge-detail],[data-fire-care-option]').forEach(function (row) {
                row.hidden=row.hasAttribute('data-charge-detail') && (!chargeControl || !chargeControl.checked)
                    || row.hasAttribute('data-fire-care-option') && (!fireCare || !fireCare.checked);
            });
        }
        if (chargeControl) chargeControl.addEventListener('change',updateBatteryFields);
        if (fireCare) fireCare.addEventListener('change',updateBatteryFields);
        updateBatteryFields();
        settings.addEventListener('input',function () { dirty=true; document.getElementById('settings-draft-state').textContent='Ungespeicherte Änderungen'; });
        settings.addEventListener('change',function () { dirty=true; document.getElementById('settings-draft-state').textContent='Ungespeicherte Änderungen'; });
        settings.addEventListener('submit',function () { dirty=false; });
        window.addEventListener('beforeunload',function (event) { if (dirty) { event.preventDefault(); event.returnValue=''; } });
    }
}());
(function () {
    'use strict';
    var dashboard=document.getElementById('fleet-dashboard');
    if (!dashboard) return;
    var grid=document.getElementById('fleet-devices'), search=document.getElementById('fleet-search'), timer=null, request=null;
    function field(card,name,text) { var node=card.querySelector('[data-field="'+name+'"]'); if (node) node.textContent=text; }
    function string(value,fallback) { return typeof value==='string' && value!=='' ? value : fallback; }
    function filter() {
        var term=search.value.trim().toLocaleLowerCase(), visible=0, cards=grid.querySelectorAll('[data-device]');
        cards.forEach(function (card) { card.hidden=card.getAttribute('data-search').toLocaleLowerCase().indexOf(term)===-1; if (!card.hidden) visible++; });
        document.getElementById('fleet-no-results').hidden=visible>0 || cards.length===0;
    }
    function updateCard(card,item) {
        card.setAttribute('data-device',item.id);
        card.setAttribute('data-search',string(item.name,item.host)+' '+item.host);
        field(card,'name',string(item.name,item.host)); field(card,'host',item.host); field(card,'model',string(item.model,'Android-Tablet'));
        var connection=['online','offline'].indexOf(item.connection_state)!==-1 ? item.connection_state : 'unknown';
        var badge=card.querySelector('[data-field="connection"]'); badge.setAttribute('data-state',connection);
        badge.querySelector('b').textContent=connection==='online' ? 'Aktuell' : connection==='offline' ? 'Kein aktueller Kontakt' : 'Noch keine Meldung';
        var battery=typeof item.battery_percent==='number' && isFinite(item.battery_percent) ? Math.max(0,Math.min(100,item.battery_percent)) : null;
        field(card,'battery',battery===null ? '—' : battery+' %');
        card.querySelector('[data-field="battery-bar"]').style.width=(battery===null ? 0 : battery)+'%';
        card.querySelector('.battery-track').setAttribute('aria-label',battery===null ? 'Akkustand unbekannt' : 'Akkustand '+battery+' Prozent');
        field(card,'charging',item.charging===true ? 'Wird geladen' : item.plugged===true ? 'Stromversorgung angeschlossen' : item.plugged===false ? 'Im Akkubetrieb' : 'Stromversorgung unbekannt');
        field(card,'wifi',string(item.wifi_ssid,'Nicht gemeldet'));
        field(card,'udp',item.udp_running===true ? 'Aktiv' : item.udp_running===false ? 'Gestoppt' : 'Nicht gemeldet');
        field(card,'display',item.display_on===true ? 'An' : item.display_on===false ? 'Aus' : 'Nicht gemeldet');
        var mqtt=item.mqtt && typeof item.mqtt==='object' ? item.mqtt : {};
        field(card,'mqtt',mqtt.delivery_confirmed===true ? 'Bestätigt' : mqtt.state==='unavailable' ? 'Nicht verfügbar' : mqtt.state==='pending' ? 'Ausstehend' : mqtt.state==='published' ? 'Unbestätigt' : 'Nicht gemeldet');
        card.querySelector('[data-field="mqtt"]').title=string(mqtt.message,'Letzter gemeldeter Veröffentlichungsstatus');
        var management=item.management && typeof item.management==='object' ? item.management : {};
        field(card,'management',string(management.message,'Noch kein Konfigurationsstand'));
        var contact=card.querySelector('[data-field="contact"]'), seen=typeof item.last_seen==='number' ? item.last_seen : null;
        var age=typeof item.age==='number' ? Math.max(0,item.age) : null;
        contact.textContent=seen===null ? 'Kontakt ausstehend' : age!==null && age<60 ? 'Kontakt vor '+Math.floor(age)+' Sek.' : age!==null && age<3600 ? 'Kontakt vor '+Math.floor(age/60)+' Min.' : 'Kontakt '+new Date(seen*1000).toLocaleDateString();
        contact.title=seen===null ? '' : new Date(seen*1000).toLocaleString();
        card.querySelector('[data-field="link"]').setAttribute('href','?form=config&device='+encodeURIComponent(item.id));
    }
    function render(value) {
        if (!value || value.ok!==true || !Array.isArray(value.devices)) throw new Error('Invalid overview');
        ['total','online','pending','mqtt_ok'].forEach(function (key) {
            var number=typeof value[key]==='number' && isFinite(value[key]) ? Math.max(0,Math.floor(value[key])) : 0;
            dashboard.querySelectorAll('[data-fleet-count="'+key+'"]').forEach(function (node) { node.textContent=String(number); });
        });
        var keep={};
        value.devices.forEach(function (item) {
            if (!item || typeof item.id!=='string' || !/^[a-f0-9]{16}$/.test(item.id) || typeof item.host!=='string') return;
            keep[item.id]=true;
            var card=grid.querySelector('[data-device="'+item.id+'"]');
            if (!card) { card=document.getElementById('fleet-card-template').content.firstElementChild.cloneNode(true); grid.appendChild(card); }
            updateCard(card,item);
        });
        grid.querySelectorAll('[data-device]').forEach(function (card) { if (!keep[card.getAttribute('data-device')]) card.remove(); });
        document.getElementById('fleet-empty').hidden=value.devices.length>0;
        if (typeof value.server_time==='number') document.getElementById('fleet-updated').textContent='Stand '+new Date(value.server_time*1000).toLocaleTimeString();
        filter();
    }
    function schedule() { clearTimeout(timer); if (!document.hidden) timer=setTimeout(poll,5000); }
    function poll() {
        clearTimeout(timer); if (document.hidden || request) return;
        var controller=new AbortController(), timeout=setTimeout(function () { controller.abort(); },5000); request=controller;
        fetch('index.php?operation=overview',{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal})
            .then(function (response) { if (!response.ok) throw new Error('status'); return response.json(); })
            .then(function (value) { render(value); document.getElementById('fleet-error').textContent=''; })
            .catch(function () { if (!document.hidden) document.getElementById('fleet-error').textContent='Live-Status konnte nicht nachgeladen werden. Die zuletzt empfangenen Werte bleiben sichtbar.'; })
            .finally(function () { clearTimeout(timeout); request=null; schedule(); });
    }
    search.addEventListener('input',filter);
    document.addEventListener('visibilitychange',function () { clearTimeout(timer); if (document.hidden && request) request.abort(); else if (!document.hidden) poll(); });
    window.addEventListener('pagehide',function () { clearTimeout(timer); if (request) request.abort(); });
    try { render(JSON.parse(document.getElementById('fleet-initial').textContent)); } catch (ignored) {}
    schedule();
}());
