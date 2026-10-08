jQuery(function($){
    let current = 0, pollTimer = null;
    let knownUnread = {};
    let currentVersion = 0;

    $('.acm-chat-item').each(function(){
        const id = String($(this).data('id'));
        knownUnread[id] = $(this).find('small').text().indexOf('New') !== -1;
    });

    function esc(s){ return $('<div>').text(s || '').html(); }

    function showAlert(message){
        let $toast = $('#acm-admin-alert');
        if (!$toast.length) $toast = $('<div id="acm-admin-alert" role="status"></div>').appendTo('body');
        $toast.stop(true,true).text(message).addClass('is-visible');
        clearTimeout(window.acmAlertTimer);
        window.acmAlertTimer = setTimeout(function(){ $toast.removeClass('is-visible'); }, 5000);
    }

    function playAlert(){
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            const ctx = new Ctx();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.06;
            osc.connect(gain); gain.connect(ctx.destination);
            osc.start(); osc.stop(ctx.currentTime + 0.18);
        } catch(e) {}
    }

    function browserNotify(title, body){
        if ('Notification' in window && Notification.permission === 'granted') {
            try { new Notification(title, {body: body}); } catch(e) {}
        }
    }

    function requestNotifications(){
        if ('Notification' in window && Notification.permission === 'default') {
            $(document).one('click', function(){
                if (Notification.permission === 'default') Notification.requestPermission();
            });
        }
    }

    function updateTitle(hasUnread){
        if (!document.titleOriginal) document.titleOriginal = document.title;
        document.title = hasUnread ? '🔔 New Chat - ' + document.titleOriginal : document.titleOriginal;
    }

    function buildChatItem(chat){
        const unreadText = chat.unread ? ' • New' : '';
        const $row = $('<div/>', {
            'class': 'acm-chat-row',
            'data-id': chat.id
        });
        const $item = $('<button/>', {
            'class': 'acm-chat-item' + (String(chat.id) === String(current) ? ' is-current' : ''),
            'data-id': chat.id,
            'type': 'button'
        }).append(
            $('<strong/>').text(chat.name || ''),
            $('<span/>').text(chat.email || ''),
            $('<small/>').text((chat.status ? chat.status.charAt(0).toUpperCase()+chat.status.slice(1) : 'Open') + unreadText)
        );
        const $delete = $('<button/>', {
            'class': 'acm-delete-user-chat',
            'type': 'button',
            'data-id': chat.id,
            'title': 'Delete user and chat permanently',
            'text': 'Delete User & Chat'
        });
        return $row.append($item, $('<div/>', {'class':'acm-chat-actions'}).append($delete));
    }

    function syncChatList(chats){
        const $list = $('.acm-chat-list');
        if (!$list.length) return;
        const ids = [];

        (chats || []).forEach(function(chat){
            const id = String(chat.id);
            ids.push(id);
            let $item = $list.find('.acm-chat-item[data-id="' + chat.id + '"]');
            let $row = $item.closest('.acm-chat-row');

            if (!$item.length) {
                $row = buildChatItem(chat);
                $item = $row.find('.acm-chat-item');
                $list.prepend($row);
                knownUnread[id] = !!chat.unread;
                showAlert('New chat started by ' + (chat.name || 'a visitor'));
                playAlert();
                browserNotify('New chat', (chat.name || 'A visitor') + ' started a new chat.');
            } else {
                const wasUnread = !!knownUnread[id];
                const unreadText = chat.unread ? ' • New' : '';
                $item.find('strong').text(chat.name || '');
                $item.find('span').text(chat.email || '');
                $item.find('small').text((chat.status ? chat.status.charAt(0).toUpperCase()+chat.status.slice(1) : 'Open') + unreadText);

                if (chat.unread && !wasUnread && id !== String(current)) {
                    showAlert('New message from ' + (chat.name || 'a visitor'));
                    playAlert();
                    browserNotify('New chat message', (chat.name || 'A visitor') + ' sent a new message.');
                }
                knownUnread[id] = !!chat.unread;
            }

            $item.toggleClass('is-current', id === String(current));
        });

        $list.find('.acm-chat-row').each(function(){
            if (ids.indexOf(String($(this).data('id'))) === -1) $(this).remove();
        });

        if (!ids.length) {
            if (!$list.find('p').length) $list.append('<p>No conversations yet.</p>');
        } else {
            $list.find('p').remove();
        }

        const $items = $list.find('.acm-chat-row').detach();
        $items.sort(function(a,b){
            return ids.indexOf(String($(a).data('id'))) - ids.indexOf(String($(b).data('id')));
        });
        $list.append($items);
        updateTitle((chats || []).some(function(c){ return c.unread; }));
    }

    function syncActive(active){
        if (!current || !active || String(active.id) !== String(current)) return;
        if (Number(active.version) !== Number(currentVersion)) {
            const hadMessages = currentVersion > 0;
            currentVersion = Number(active.version) || 0;
            render(active.messages || []);

            if (hadMessages) {
                const last = (active.messages || []).slice(-1)[0];
                if (last && last.sender === 'customer') {
                    showAlert('New message from ' + ($('#acm-admin-customer strong').text() || 'visitor'));
                    playAlert();
                    browserNotify('New chat message', 'A visitor sent a new message.');
                }
            }

            // Admin is actively viewing this chat, so mark it read.
            $.post(ACM_ADMIN.ajax, {
                action:'acm_admin_load', nonce:ACM_ADMIN.nonce, chat_id:current
            }, function(r){
                if (r.success) {
                    knownUnread[String(current)] = false;
                    const $item = $('.acm-chat-item[data-id="'+current+'"]');
                    if ($item.length) {
                        const status = r.data.status || 'open';
                        $item.find('small').text(status.charAt(0).toUpperCase()+status.slice(1));
                    }
                }
            });
        }
    }

    function poll(){
        $.post(ACM_ADMIN.ajax, {
            action:'acm_admin_poll', nonce:ACM_ADMIN.nonce, current_chat_id: current
        }, function(r){
            if (r.success) {
                syncChatList(r.data.chats || []);
                syncActive(r.data.active);
            }
        }).always(function(){
            clearTimeout(pollTimer);
            pollTimer = setTimeout(poll, 1500);
        });
    }

    function load(id){
        current = Number(id) || 0;
        currentVersion = 0;
        $('.acm-chat-item').removeClass('is-current');
        $('.acm-chat-item[data-id="'+current+'"]').addClass('is-current');
        $.post(ACM_ADMIN.ajax,{action:'acm_admin_load',nonce:ACM_ADMIN.nonce,chat_id:current},function(r){
            if(!r.success)return;
            $('#acm-admin-empty').hide();
            $('#acm-admin-active').show();
            $('#acm-admin-customer').html('<strong>'+esc(r.data.name)+'</strong><span>'+esc(r.data.email)+'</span>');
            currentVersion = Number(r.data.version) || 0;
            render(r.data.messages);
            knownUnread[String(current)] = false;
            const $item = $('.acm-chat-item[data-id="'+current+'"]');
            if ($item.length) {
                const status = r.data.status || 'open';
                $item.find('small').text(status.charAt(0).toUpperCase()+status.slice(1));
            }
        });
    }

    function render(ms){
        let h='';
        (ms||[]).forEach(function(m){
            h+='<div class="acm-admin-msg '+(m.sender === 'admin' ? 'admin' : 'customer')+'"><div>'+esc(m.message)+'</div><small>'+esc(m.time)+'</small></div>';
        });
        const box = $('#acm-admin-messages')[0];
        $('#acm-admin-messages').html(h);
        if (box) box.scrollTop = box.scrollHeight;
    }

    $(document).on('click','.acm-chat-item',function(){ load($(this).data('id')); });

    function sendAdminMessage(){
        const msg = $('#acm-admin-message').val().trim();
        if(!msg || !current) return;
        const $send = $('#acm-admin-send').prop('disabled', true);
        $.post(ACM_ADMIN.ajax,{action:'acm_admin_reply',nonce:ACM_ADMIN.nonce,chat_id:current,message:msg},function(r){
            if(r.success){
                $('#acm-admin-message').val('');
                currentVersion = Number(r.data.version) || currentVersion + 1;
                render(r.data.messages);
                knownUnread[String(current)] = false;
            } else if (r.data && r.data.message) {
                showAlert(r.data.message);
            }
        }).always(function(){ $send.prop('disabled', false); });
    }

    $('#acm-admin-send').on('click', sendAdminMessage);
    $('#acm-admin-message').on('keydown', function(e){
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            sendAdminMessage();
        }
    });

    $('#acm-admin-close').on('click',function(){
        if(!current)return;
        $.post(ACM_ADMIN.ajax,{action:'acm_close_chat',nonce:ACM_ADMIN.nonce,chat_id:current},function(r){
            if(r.success) load(current);
        });
    });

    $(document).on('click','.acm-delete-user-chat',function(e){
        e.preventDefault();
        e.stopPropagation();
        const $btn = $(this);
        const id = Number($btn.data('id')) || 0;
        if (!id) return;
        const $row = $btn.closest('.acm-chat-row');
        const name = $row.find('.acm-chat-item strong').text() || 'this user';
        if (!window.confirm('Permanently delete ' + name + ' and all chat messages? This cannot be undone.')) return;
        $btn.prop('disabled', true);
        $.post(ACM_ADMIN.ajax,{action:'acm_delete_chat',nonce:ACM_ADMIN.nonce,chat_id:id},function(r){
            if(r.success){
                $row.remove();
                delete knownUnread[String(id)];
                if (String(current) === String(id)) {
                    current = 0; currentVersion = 0;
                    $('#acm-admin-active').hide();
                    $('#acm-admin-empty').show().text('Select a conversation.');
                }
                if(!$('.acm-chat-item').length) $('.acm-chat-list').html('<p>No conversations yet.</p>');
                showAlert('User and chat permanently deleted.');
            } else {
                showAlert((r.data && r.data.message) || 'Unable to delete user/chat.');
            }
        }).always(function(){ $btn.prop('disabled', false); });
    });

    $('#acm-admin-delete').on('click',function(){
        if(!current)return;
        const id = current;
        const name = $('#acm-admin-customer strong').text() || 'this chat';
        if(!window.confirm('Permanently delete ' + name + '? This will remove the user chat and all messages and cannot be undone.')) return;
        const $btn = $(this).prop('disabled', true);
        $.post(ACM_ADMIN.ajax,{action:'acm_delete_chat',nonce:ACM_ADMIN.nonce,chat_id:id},function(r){
            if(r.success){
                $('.acm-chat-row[data-id="'+id+'"]').remove();
                delete knownUnread[String(id)];
                current = 0; currentVersion = 0;
                $('#acm-admin-active').hide();
                $('#acm-admin-empty').show().text('Select a conversation.');
                if(!$('.acm-chat-item').length) $('.acm-chat-list').html('<p>No conversations yet.</p>');
                showAlert('Chat permanently deleted.');
            } else {
                showAlert((r.data && r.data.message) || 'Unable to delete chat.');
            }
        }).always(function(){ $btn.prop('disabled', false); });
    });

    requestNotifications();
    poll();
});
