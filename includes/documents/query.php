<?php
function loadDocumentsQueryContext(PDO $pdo): array {
    $documents = $pdo->query('SELECT * FROM documents ORDER BY sort_order ASC, id DESC')->fetchAll();
    return compact('documents');
}
