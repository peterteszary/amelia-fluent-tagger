jQuery(document).ready(function($) {
    console.log('Amelia Tagger Admin Script Loaded.'); // Alapvető ellenőrzés, hogy a fájl betöltődött-e

    var rulesContainer = $('#aft-rules-container');
    var ruleTemplateHtml;

    // Próbáljuk meg a wp.template használatát, de legyen fallback
    if (typeof wp !== 'undefined' && typeof wp.template !== 'undefined' && $('#tmpl-aft-rule-template-html').length) {
        ruleTemplateHtml = wp.template('aft-rule-template-html');
         console.log('WP Template function found and used.');
    } else if ($('#aft-rule-template').length) { // A korábbi template ID-val
        ruleTemplateHtml = $('#aft-rule-template').html();
        console.log('Fallback to direct HTML template.');
    } else {
        console.error('Amelia Tagger: Rule template HTML not found!');
        ruleTemplateHtml = ''; // Hogy ne legyen undefined hiba később
    }

    // Új szabály hozzáadása
    $('#aft-add-rule').on('click', function() {
        console.log('Add rule button clicked.');
        if (!ruleTemplateHtml) {
            alert('Hiba: A szabály sablon nem található!');
            return;
        }
        var ruleIndex = rulesContainer.find('.aft-rule-item').length;
        var newRuleContent;
        if (typeof ruleTemplateHtml === 'function') { // Ha wp.template adta vissza
            newRuleContent = ruleTemplateHtml({ __INDEX__: ruleIndex, __INDEX_DISPLAY__: ruleIndex + 1 });
        } else { // Ha stringként van meg a HTML
            newRuleContent = ruleTemplateHtml
                .replace(/__INDEX_DISPLAY__/g, ruleIndex + 1)
                .replace(/__INDEX__/g, ruleIndex);
        }
        rulesContainer.append(newRuleContent);
    });

    // Szabály törlése
    rulesContainer.on('click', '.aft-remove-rule', function() {
        console.log('Remove rule button clicked.');
        if (confirm(aft_ajax_obj.i18n.confirm_delete)) {
            $(this).closest('.aft-rule-item').remove();
        }
    });

    // Szabályok mentése
    $('#aft-rules-form').on('submit', function(e) {
        e.preventDefault();
        console.log('Save rules form submitted.');
        $('#aft-save-spinner').css('visibility', 'visible');
        $('#aft-save-rules-submit').prop('disabled', true);
        $('#aft-save-feedback').hide().empty();

        var formData = $(this).serializeArray();
        formData.push({name: "action", value: "aft_save_rules"});
        // A nonce_save_rules már a formban van a wp_nonce_field miatt, de a JS oldalon is ellenőrizhetjük
        // formData.push({name: "nonce_save_rules", value: aft_ajax_obj.nonce_save_rules}); 
        // A wp_nonce_field által generált mező neve 'aft_save_rules_nonce_field' lesz,
        // a check_ajax_referer pedig a 'nonce_save_rules'-t várja a POST-ban.
        // A PHP oldalon a check_ajax_referer('aft_save_rules_nonce', 'nonce_save_rules');
        // a nonce_save_rules nevű POST változót keresi, aminek az értéke a wp_create_nonce('aft_save_rules_nonce')
        // A wp_nonce_field('aft_save_rules_action', 'aft_save_rules_nonce_field');
        // létrehoz egy hidden inputot 'aft_save_rules_nonce_field' névvel.
        // A check_ajax_referer('aft_save_rules_action', 'aft_save_rules_nonce_field'); lenne a helyes, ha a nonce mező nevét használjuk.
        // Vagy a wp_localize_script-ben átadott nonce-t használjuk, és a check_ajax_referer ezt a nevet várja.
        // Maradjunk a wp_localize_script-ben átadott nonce névnél a JS oldalon, és a PHP-ban is ezt ellenőrizzük.
        // A form serializeArray() már tartalmazza a nonce mezőt, ha a wp_nonce_field a formon belül van.
        // A biztonság kedvéért a wp_localize_script-ben átadott nonce-t is használhatjuk.
        // A jelenlegi PHP `check_ajax_referer('aft_save_rules_nonce', 'nonce_save_rules');`
        // azt jelenti, hogy a JS-nek `nonce_save_rules` néven kell küldenie a nonce értékét.
        // A `$(this).serialize()` jobb lenne, mert az a form összes mezőjét küldi.

        $.ajax({
            url: aft_ajax_obj.ajax_url,
            type: 'POST',
            data: $(this).serialize() + "&action=aft_save_rules&_ajax_nonce=" + aft_ajax_obj.nonce_save_rules, // Egyszerűsített adatküldés
            success: function(response) {
                if (response.success) {
                    $('#aft-save-feedback').removeClass('notice-error').addClass('notice-success').html('<p>' + response.data.message + '</p>').fadeIn();
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : aft_ajax_obj.i18n.error_unknown;
                    $('#aft-save-feedback').removeClass('notice-success').addClass('notice-error').html('<p>' + errorMessage + '</p>').fadeIn();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("Save rules AJAX error:", textStatus, errorThrown, jqXHR.responseText);
                $('#aft-save-feedback').removeClass('notice-success').addClass('notice-error').html('<p>' + aft_ajax_obj.i18n.error_server + '</p>').fadeIn();
            },
            complete: function() {
                $('#aft-save-spinner').css('visibility', 'hidden');
                $('#aft-save-rules-submit').prop('disabled', false);
                setTimeout(function() {
                    $('#aft-save-feedback').fadeOut(function() { $(this).empty(); });
                }, 7000);
            }
        });
    });

    // "Tag-elés Most" gomb kezelése
    rulesContainer.on('click', '.aft-tag-now-button', function() {
        console.log('Tag Now button clicked.'); // Ellenőrző log
        var $button = $(this);
        var $ruleItem = $button.closest('.aft-rule-item');
        var $feedbackDiv = $ruleItem.find('.aft-tag-now-feedback');

        // Adatok kiolvasása a DOM-ból
        var eventId = $ruleItem.find('.aft-select-event').val();
        var tagId = $ruleItem.find('.aft-select-tag').val();
        var infoField = $ruleItem.find('.aft-input-custom-field').val();

        console.log('Event ID:', eventId, 'Tag ID:', tagId, 'Info Field:', infoField); // Adatok logolása

        if (!eventId || !tagId) {
            $feedbackDiv.removeClass('notice-success notice-info').addClass('notice-error').html('<p>' + aft_ajax_obj.i18n.missing_event_tag + '</p>').fadeIn();
            setTimeout(function() { $feedbackDiv.fadeOut(function() { $(this).empty(); }); }, 5000);
            return;
        }

        $feedbackDiv.removeClass('notice-success notice-error').addClass('notice-info').html('<p>' + aft_ajax_obj.i18n.tag_now_processing + '</p>').fadeIn();
        $button.prop('disabled', true);

        $.ajax({
            url: aft_ajax_obj.ajax_url,
            type: 'POST',
            data: {
                action: 'aft_tag_now',
                nonce: aft_ajax_obj.nonce_tag_now, // A lokalizált nonce használata
                event_id: eventId,
                tag_id: tagId,
                info_field: infoField
            },
            success: function(response) {
                console.log('Tag Now AJAX success:', response);
                if (response.success) {
                    var message = response.data.message || aft_ajax_obj.i18n.tag_now_success;
                     if (response.data.tagged_count !== undefined && response.data.tagged_count === 0 && response.data.message && response.data.message.includes("Nincsenek")) {
                        $feedbackDiv.removeClass('notice-success notice-error').addClass('notice-info').html('<p>' + message + '</p>');
                    } else if (response.data.tagged_count !== undefined && response.data.tagged_count === 0) {
                        $feedbackDiv.removeClass('notice-success notice-error').addClass('notice-info').html('<p>' + message + ' (' + aft_ajax_obj.i18n.tag_now_no_bookings + ')</p>');
                    }
                    else {
                        $feedbackDiv.removeClass('notice-info notice-error').addClass('notice-success').html('<p>' + message + '</p>');
                    }
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : aft_ajax_obj.i18n.tag_now_error;
                    $feedbackDiv.removeClass('notice-success notice-info').addClass('notice-error').html('<p>' + errorMessage + '</p>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("Tag Now AJAX error:", textStatus, errorThrown, jqXHR.responseText);
                $feedbackDiv.removeClass('notice-success notice-info').addClass('notice-error').html('<p>' + aft_ajax_obj.i18n.error_server + '</p>');
            },
            complete: function() {
                $button.prop('disabled', false);
                setTimeout(function() { $feedbackDiv.fadeOut(function() { $(this).empty(); }); }, 7000);
            }
        });
    });
});
