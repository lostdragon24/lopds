<?php

// api/rating.php

require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../lib/Database.php';
require_once __DIR__.'/../lib/PageCache.php';
require_once __DIR__.'/../init.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();

    // Простейшая валидация IP
    $userIp = $_SERVER['REMOTE_ADDR'];
    if (!filter_var($userIp, FILTER_VALIDATE_IP)) {
        $userIp = '0.0.0.0';
    }

    // Получаем входные данные
    $input = file_get_contents('php://input');
    if (!$input) {
        throw new Exception(__('error_no_input'));
    }

    $data = json_decode($input, true);
    if (!$data) {
        throw new Exception(__('error_invalid_json'));
    }

    if (!isset($data['action'])) {
        throw new Exception(__('error_action_not_specified'));
    }

    $action = $data['action'];

    // Проверка CSRF для всех действий кроме get_rating
    if ('get_rating' !== $action) {
        if (!isset($data['csrf_token'])) {
            error_log('CSRF token missing in request');
            echo json_encode([
                'success' => false,
                'message' => __('error_csrf_missing'),
            ]);
            exit;
        }

        if (PHP_SESSION_NONE === session_status()) {
            session_start();
        }

        $valid = isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $data['csrf_token']);
        session_write_close();

        if (!$valid) {
            error_log('CSRF validation failed for action: '.$action);
            echo json_encode([
                'success' => false,
                'message' => __('error_csrf_invalid'),
            ]);
            exit;
        }

        error_log('CSRF validation passed for action: '.$action);
    }

    switch ($action) {
        case 'rate':
            if (!isset($data['book_id'], $data['rating'])) {
                throw new Exception(__('error_missing_params'));
            }

            $bookId = (int) $data['book_id'];
            $rating = (int) $data['rating'];

            if ($rating < 1 || $rating > 5) {
                throw new Exception(__('error_invalid_rating'));
            }

            // Проверяем существование книги
            $book = $db->getBook($bookId);
            if (!$book) {
                throw new Exception(__('book_not_found'));
            }

            // Проверяем, оценивал ли уже пользователь
            $stmt = $db->getConnection()->prepare(
                'SELECT id FROM book_ratings WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                // Обновляем (created_at не трогаем — это дата первой оценки)
                $stmt = $db->getConnection()->prepare(
                    'UPDATE book_ratings SET rating = ? WHERE book_id = ? AND user_ip = ?'
                );
                $stmt->execute([$rating, $bookId, $userIp]);
                $result = 'updated';
            } else {
                // Вставляем
                $stmt = $db->getConnection()->prepare(
                    'INSERT INTO book_ratings (book_id, user_ip, rating, created_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)'
                );
                $stmt->execute([$bookId, $userIp, $rating]);
                $result = 'added';
            }

            // Сбрасываем кэш страниц
            PageCache::invalidateUserPages($userIp);

            // Получаем обновленный рейтинг
            $stmt = $db->getConnection()->prepare(
                'SELECT COUNT(*) as votes, AVG(rating) as average 
                 FROM book_ratings WHERE book_id = ?'
            );
            $stmt->execute([$bookId]);
            $ratingData = $stmt->fetch();

            // Получаем оценку пользователя
            $stmt = $db->getConnection()->prepare(
                'SELECT rating FROM book_ratings WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);
            $userRating = $stmt->fetch();

            echo json_encode([
                'success' => true,
                'message' => __('rating_saved'),
                'result' => $result,
                'rating' => [
                    'votes' => (int) $ratingData['votes'],
                    'average' => (float) $ratingData['average'],
                    'average_rounded' => round((float) $ratingData['average'] * 2) / 2,
                ],
                'user_rating' => $userRating ? (int) $userRating['rating'] : $rating,
            ]);
            break;

        case 'toggle_favorite':
            if (!isset($data['book_id'])) {
                throw new Exception(__('error_missing_params'));
            }

            $bookId = (int) $data['book_id'];

            // Проверяем существование книги
            $book = $db->getBook($bookId);
            if (!$book) {
                throw new Exception(__('book_not_found'));
            }

            // Проверяем, есть ли уже в избранном
            $stmt = $db->getConnection()->prepare(
                'SELECT id FROM book_favorites WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);

            if ($stmt->fetch()) {
                // Удаляем
                $stmt = $db->getConnection()->prepare(
                    'DELETE FROM book_favorites WHERE book_id = ? AND user_ip = ?'
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'removed';
                $isFavorite = false;
                $message = __('favorites_removed');
            } else {
                $stmt = $db->getConnection()->prepare(
                    'INSERT INTO book_favorites (book_id, user_ip) VALUES (?, ?)'
                );
                $stmt->execute([$bookId, $userIp]);
                $result = 'added';
                $isFavorite = true;
                $message = __('favorites_added');
            }

            // Сбрасываем кэш страниц
            PageCache::invalidateUserPages($userIp);

            echo json_encode([
                'success' => true,
                'message' => $message,
                'result' => $result,
                'is_favorite' => $isFavorite,
            ]);
            break;

        case 'get_rating':
            if (!isset($data['book_id'])) {
                throw new Exception(__('error_missing_params'));
            }

            $bookId = (int) $data['book_id'];

            // Получаем общий рейтинг
            $stmt = $db->getConnection()->prepare(
                'SELECT COUNT(*) as votes, AVG(rating) as average 
                 FROM book_ratings WHERE book_id = ?'
            );
            $stmt->execute([$bookId]);
            $ratingData = $stmt->fetch();

            // Получаем оценку пользователя
            $stmt = $db->getConnection()->prepare(
                'SELECT rating FROM book_ratings WHERE book_id = ? AND user_ip = ?'
            );
            $stmt->execute([$bookId, $userIp]);
            $userRating = $stmt->fetch();

            // Получаем распределение оценок
            $distribution = [0, 0, 0, 0, 0];
            $stmt = $db->getConnection()->prepare(
                'SELECT rating, COUNT(*) as count 
                 FROM book_ratings 
                 WHERE book_id = ? 
                 GROUP BY rating 
                 ORDER BY rating DESC'
            );
            $stmt->execute([$bookId]);
            $distResults = $stmt->fetchAll();

            foreach ($distResults as $row) {
                $index = 5 - $row['rating'];
                $distribution[$index] = (int) $row['count'];
            }

            echo json_encode([
                'success' => true,
                'rating' => [
                    'votes' => (int) $ratingData['votes'],
                    'average' => (float) $ratingData['average'],
                    'average_rounded' => round((float) $ratingData['average'] * 2) / 2,
                    'distribution' => $distribution,
                ],
                'user_rating' => $userRating ? (int) $userRating['rating'] : 0,
            ]);
            break;

        default:
            throw new Exception(__('error_unknown_action').': '.$action);
    }
} catch (Exception $e) {
    error_log('Rating API Error: '.$e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => __('error_occurred').': '.$e->getMessage(),
    ]);
}
