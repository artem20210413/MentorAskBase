<?php

// Помилки обробки документів (зберігаються в documents.failure_reason мовою APP_LOCALE на момент обробки).

return [
    'no_searchable_text' => 'No searchable text could be extracted from the document.',
    'text_unreadable' => 'Could not read the text file.',
    'unsupported_encoding' => 'Could not read the text: the file looks binary or has an unsupported encoding (UTF-8, UTF-16 and Windows-1251 are supported).',
    'store_failed' => 'Could not store the document file.',
    'word_corrupted' => 'The file is corrupted, password-protected or has an unsupported format: :error',
    'pdf_corrupted' => 'The file is corrupted or has an unsupported format: :error',
];
