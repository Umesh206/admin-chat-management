jQuery(function($){
    const $widget = $('#acm-chat-widget');
    const $open = $('#acm-open');
    const $box = $('#acm-box');

    function setLauncherVisible(visible) {
        if (visible) {
            $widget.removeClass('acm-is-open');
            $open.attr('aria-hidden', 'false').removeAttr('hidden').css({
                'display':'inline-block',
                'visibility':'visible',
                'opacity':'1',
                'pointer-events':'auto'
            });
        } else {
            $widget.addClass('acm-is-open');
            $open.attr('aria-hidden', 'true').attr('hidden', 'hidden').css({
                'display':'none',
                'visibility':'hidden',
                'opacity':'0',
                'pointer-events':'none'
            });
        }
    }

    function syncLauncher() {
        const isOpen = $box.is(':visible');
        setLauncherVisible(!isOpen);
    }

    let chatId = localStorage.getItem('acm_chat_id') || '';
    let token = localStorage.getItem('acm_chat_token') || '';
    let timer = null;

    $('#acm-open').on('click', function(e){
        e.preventDefault();
        $('#acm-box').css('display', 'block');
        setLauncherVisible(false);
        if(chatId && token){
            $('#acm-start').hide();
            $('#acm-chat').show();
            loadMessages();
        } else {
            $('#acm-start').show();
            $('#acm-chat').hide();
        }
    });
    $('#acm-close').on('click', function(e){
        e.preventDefault();
        $('#acm-box').css('display', 'none');
        setLauncherVisible(true);
    });

    $('#acm-start-btn').on('click', function(){
        const name = $('#acm-name').val().trim();
        const email = $('#acm-email').val().trim();
        $('#acm-start-error').text('');
        $.post(ACM.ajax, {action:'acm_start_chat', nonce:ACM.nonce, name:name, email:email}, function(r){
            if(!r.success){ $('#acm-start-error').text(r.data.message); return; }
            chatId = r.data.chat_id; token = r.data.token;
            localStorage.setItem('acm_chat_id', chatId);
            localStorage.setItem('acm_chat_token', token);
            $('#acm-start').hide(); $('#acm-chat').show(); setLauncherVisible(false); loadMessages();
        });
    });

    function escapeHtml(s){
        return $('<div>').text(s || '').html();
    }
    function render(messages){
        let html='';
        (messages || []).forEach(m=>{
            html += '<div class="acm-msg '+(m.sender==='customer'?'customer':'admin')+'"><div>'+escapeHtml(m.message)+'</div><small>'+escapeHtml(m.time)+'</small></div>';
        });
        $('#acm-messages').html(html).scrollTop($('#acm-messages')[0].scrollHeight);
    }
    function loadMessages(){
        if(!chatId || !token) return;
        $.post(ACM.ajax, {action:'acm_get_messages', nonce:ACM.nonce, chat_id:chatId, token:token}, function(r){
            if(r.success) {
                render(r.data.messages);
            } else {
                // The admin may have permanently deleted this chat. Reset the visitor widget
                // so the visitor can start a fresh conversation without refreshing the page.
                chatId = '';
                token = '';
                localStorage.removeItem('acm_chat_id');
                localStorage.removeItem('acm_chat_token');
                $('#acm-chat').hide();
                $('#acm-start').show();
                $('#acm-messages').empty();
            }
        });
        clearTimeout(timer); timer=setTimeout(loadMessages, 3000);
    }
    $('#acm-send').on('click', function(){
        const message=$('#acm-message').val().trim();
        if(!message) return;
        $.post(ACM.ajax, {action:'acm_send_message', nonce:ACM.nonce, chat_id:chatId, token:token, message:message}, function(r){
            if(r.success){ $('#acm-message').val(''); loadMessages(); }
        });
    });
    $('#acm-message').on('keydown', function(e){
        if(e.key === 'Enter' && !e.shiftKey && !e.isComposing){
            e.preventDefault();
            $('#acm-send').click();
        }
    });

    // Keep the launcher hidden for the entire time the chat window is open,
    // even if the theme or another script changes the button styles.
    if ($box.length && window.MutationObserver) {
        const observer = new MutationObserver(function(){
            syncLauncher();
        });
        observer.observe($box[0], { attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
    }

    syncLauncher();
});
