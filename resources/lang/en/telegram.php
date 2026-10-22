<?php

return [

    'greeting' => "Hi! 👋 I'm an assistant bot. I can help you find answers in our knowledge base: find the right document, briefly summarize it, or just answer your question in chat.\n\nTo start using the bot, please confirm your phone number — tap the button below. After that our administrators will review your request, and I'll let you know as soon as access is granted.",
    'btn_share_contact' => 'Share contact',

    'btn_menu_language' => '🌐 Language',
    'btn_menu_help' => '❓ Help',
    'btn_menu_docs' => '📄 Document list',
    'btn_menu_doc_info' => 'ℹ️ Document info',
    'menu_title' => 'Main menu:',
    'choose_language' => 'Choose a language:',
    'language_saved' => 'Language saved.',
    'help_text' => "🌐 Language — change the conversation language\n❓ Help — this help\n📄 Document list — list of documents\nℹ️ Document info — a short summary of a document by number",

    'docs_empty' => 'There are no documents yet.',
    'docs_nav_back' => '◀️ Back',
    'docs_nav_next' => 'Next ▶️',
    'doc_not_found' => 'No document found with that number.',
    'doc_summary_unavailable' => 'Could not get a summary of this document.',
    'doc_info_prompt' => 'Send the document number from the list.',

    'invalid_phone' => 'Could not recognize the phone number. Please try again.',
    'duplicate_phone' => 'This phone number is already registered in the system.',
    'application_submitted' => 'Thank you! Your request has been sent to the administrators for review.',
    'application_already_pending' => 'Your request is already under review.',
    'reapply_after' => 'You can reapply after :date.',

    'access_denied_pending' => 'Your request is still under review. Please wait for a decision from the administrators.',
    'access_denied_rejected' => 'Your request has been rejected.',
    'access_denied_deactivated' => 'Your access has been deactivated.',
    'access_denied_default' => 'No access.',

    'free_chat_error' => 'Sorry, there was a temporary error on our side. Please try again later.',

    'otp_code_message' => 'Your sign-in code: :code',

    'applicant_approved' => 'Congratulations! Your request has been approved, bot access is open. Send anything to see the menu.',
    'applicant_rejected' => 'Unfortunately, your request has been rejected.',

    'moderation_card' => "New access request\nName: :name\nPhone: :phone\nUsername: :username\nDate: :date",
    'moderation_approve_button' => '✅ Approve',
    'moderation_reject_button' => '❌ Reject',
    'moderation_decided_approved' => '✅ Approved (:actor)',
    'moderation_decided_rejected' => '❌ Rejected (:actor)',
    'moderation_reminder_label' => '⏰ reminder sent again',
    'moderation_unauthorized' => 'You do not have permission for this action.',
    'moderation_already_processed' => 'This request has already been processed.',
    'moderation_decision_thanks' => 'Thanks, the decision has been recorded.',

];
