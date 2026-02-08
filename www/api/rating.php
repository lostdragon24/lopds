<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$userIp = $_SERVER['REMOTE_ADDR'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['action'])) {
        echo json_encode(['success' => false, 'message' => 'Action not specified']);
        exit;
    }
    
    try {
        switch ($data['action']) {
            case 'rate':
                if (!isset($data['book_id']) || !isset($data['rating'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
                    exit;
                }
                
                $bookId = (int)$data['book_id'];
                $rating = (int)$data['rating'];
                
                if ($rating < 1 || $rating > 5) {
                    echo json_encode(['success' => false, 'message' => 'Rating must be between 1 and 5']);
                    exit;
                }
                
                $result = $db->rateBook($bookId, $rating, $userIp);
                $newRating = $db->getBookRating($bookId);
                
                echo json_encode([
                    'success' => true,
                    'result' => $result,
                    'rating' => $newRating,
                    'message' => $result === 'added' ? 'Rating added' : 'Rating updated'
                ]);
                break;
                
            case 'toggle_favorite':
                if (!isset($data['book_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing book_id']);
                    exit;
                }
                
                $bookId = (int)$data['book_id'];
                $result = $db->toggleFavorite($bookId, $userIp);
                $isFavorite = $db->isBookInFavorites($bookId, $userIp);
                
                echo json_encode([
                    'success' => true,
                    'result' => $result,
                    'is_favorite' => $isFavorite,
                    'message' => $result === 'added' ? 'Added to favorites' : 'Removed from favorites'
                ]);
                break;
                
            case 'get_rating':
                if (!isset($data['book_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Missing book_id']);
                    exit;
                }
                
                $bookId = (int)$data['book_id'];
                $rating = $db->getBookRating($bookId);
                $userRating = $db->getUserRating($bookId, $userIp);
                
                echo json_encode([
                    'success' => true,
                    'rating' => $rating,
                    'user_rating' => $userRating
                ]);
                break;
                
            case 'get_favorites':
                $page = isset($data['page']) ? (int)$data['page'] : 1;
                $perPage = isset($data['per_page']) ? (int)$data['per_page'] : 20;
                
                $favorites = $db->getUserFavorites($userIp, $page, $perPage);
                $count = $db->getUserFavoritesCount($userIp);
                
                echo json_encode([
                    'success' => true,
                    'favorites' => $favorites,
                    'count' => $count,
                    'page' => $page,
                    'total_pages' => ceil($count / $perPage)
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>