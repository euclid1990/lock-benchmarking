<?php

use Carbon\Carbon;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class Controller
{
    const WAIT = 30;    // second

    const HTTP_OK = 200;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_NOT_ACCEPTABLE = 406;
    const HTTP_LOCKED = 423;

    protected $container;
    protected $db;
    protected $log;
    protected $view;
    protected $config;
    protected $wait;    // Time to sleep

    // Constructor receives container instance
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->db = $this->container->get('db');
        $this->log = $this->container->get('log');
        $this->view = $this->container->get('view');
        $this->config = $this->container->get('config');
    }

    protected function parse(Request $request)
    {
        // Set idle time for processing
        $this->wait = $request->getQueryParams()['wait'] ?? self::WAIT;
        // Parse post json data from request body
        $body = $request->getParsedBody();
        // Require params validation
        if (!isset($body['user_id'], $body['screen_code'], $body['seat_code'])) {
            return new Exception('Required parameters is not set.');
        }
        // Database existing validation
        [ 'user_id' => $userId, 'movie_code' => $movieCode, 'screen_code' => $screenCode, 'seat_code' => $seatCode ] = $body;
        // Retrieve record in database
        $userId = $this->db->table('users')->select('id')->where('id', $userId)->pluck('id')->first();
        $movieId = $this->db->table('movies')->select('id')->where('code', $movieCode)->pluck('id')->first();
        $screenId = $this->db->table('screens')->select('id')->where('code', $screenCode)->pluck('id')->first();
        $seatId = $this->db->table('seats')->select('id')->where('code', $seatCode)->pluck('id')->first();
        // If have any record does not exist
        if (is_null($userId) || is_null($screenId) || is_null($seatId)) {
            return new Exception('Database records is not existing.');
        }
        // Return parse array from request body
        return [
            'user_id' => $userId,
            'movie_id' => $movieId,
            'screen_id' => $screenId,
            'seat_id' => $seatId,
        ];
    }

    public function apiResponse(Response $response, int $code, array $data = [], string $msg = '')
    {
        $response->getBody()->write(json_encode([
            'code' => $code,
            'data' => $data,
            'message' => $msg,
        ]));
        return $response->withHeader('Content-Type', 'application/json')
            ->withStatus($code);
    }

    public function addTimestamp(array $data = [])
    {
        // Current time
        $now = Carbon::now();
        return array_merge($data, [ 'created_at' => $now, 'updated_at' => $now ]);
    }

    /* Home page - Listing api endpoint & booking result */
    public function index(Request $request, Response $response, $args)
    {
        $bookings = $this->db->table('bookings')
                    ->join('users', 'users.id', '=', 'bookings.user_id')
                    ->join('movies', 'movies.id', '=', 'bookings.movie_id')
                    ->join('screens', 'screens.id', '=', 'bookings.screen_id')
                    ->join('seats', 'seats.id', '=', 'bookings.seat_id')
                    ->select(
                        'users.id as user_id', 'users.name as user_name',
                        'movies.title as movie_title', 'screens.code as screen_code', 'seats.code as seat_code',
                        'bookings.id as booking_id', 'bookings.created_at as booking_created_at', 'bookings.updated_at as booking_updated_at'
                    )
                    ->get();
        $response = $this->view->render($response, 'index.phtml', [ 'bookings' => $bookings ]);
        return $response;
    }

    /* Not use any lock technique */
    public function noLock(Request $request, Response $response, $args) {
        $data = $this->parse($request);
        // Validate request payload
        if ($data instanceof Exception) {
            return $this->apiResponse($response, self::HTTP_BAD_REQUEST, [], $data->getMessage());
        }
        // Check for free seat
        $booking = $this->db->table('bookings')->select('id')
                            ->where('user_id', $data['user_id'])
                            ->where('movie_id', $data['movie_id'])
                            ->where('screen_id', $data['screen_id'])
                            ->where('seat_id', $data['seat_id'])
                            ->first();
        // If seat is free, book this seat
        if (is_null($booking)) {
            // Waited long enough for other incoming request to run
            sleep($this->wait);
            // Create new booking
            $data = $this->addTimestamp($data);
            $id = $this->db->table('bookings')->insertGetId($data);
            return $this->apiResponse($response, self::HTTP_OK, ['id' => $id, 'request' => $data], "Booking is created!");
        }
        return $this->apiResponse($response, self::HTTP_NOT_ACCEPTABLE, [], "Booking is not accepted!");
    }

    /* Use database lock for update technique */
    /* Require seed bookings placeholder before run it */
    public function databaseLockForUpdate(Request $request, Response $response, $args)
    {
        $data = $this->parse($request);
        // Validate request payload
        if ($data instanceof Exception) {
            return $this->apiResponse($response, self::HTTP_BAD_REQUEST, [], $data->getMessage());
        }
        $conn = $this->db->getConnection();
        try {
            /* Start new Illuminate database transaction */
            $conn->getPdo()->beginTransaction();
            // Prevents the rows from being modified or from being selected with another shared lock
            // select * from bookings where id = ? for update
            $booking = $this->db->table('bookings')
                                ->where('movie_id', $data['movie_id'])
                                ->where('screen_id', $data['screen_id'])
                                ->where('seat_id', $data['seat_id'])
                                ->lockForUpdate()->first();
            // Notice about aquire lock
            $this->log->info('Lock on existing record is aquired:', $data);
            // If booking is existing and this seat is free, book it !
            if ($booking && is_null($booking->user_id)) {
                // Waited long enough for other incoming request to run
                sleep($this->wait);
                // Update existing booking with user_id
                $data = $this->addTimestamp($data);
                $id = $this->db->table('bookings')
                                ->where('movie_id', $data['movie_id'])
                                ->where('screen_id', $data['screen_id'])
                                ->where('seat_id', $data['seat_id'])
                                ->update($data);
                /* Commit all changes into database */
                $conn->getPdo()->commit();
                return $this->apiResponse($response, self::HTTP_OK, ['id' => $id, 'request' => $data], "Booking is created!");
            }
        } catch (Exception $e) {
            /* Rollback if failover */
            $conn->getPdo()->rollback();
            return $this->apiResponse($response, self::HTTP_LOCKED, [], $e->getMessage());
        }
        return $this->apiResponse($response, self::HTTP_NOT_ACCEPTABLE, [], "Booking is not accepted!");
    }

    /* Use database lock for insert technique */
    /* Don't seed bookings placeholder before run it => We will face Deadlock problem */
    public function databaseLockForInsert(Request $request, Response $response, $args)
    {
        $data = $this->parse($request);
        // Validate request payload
        if ($data instanceof Exception) {
            return $this->apiResponse($response, self::HTTP_BAD_REQUEST, [], $data->getMessage());
        }
        $conn = $this->db->getConnection();
        try {
            /* Start new Illuminate database transaction */
            $conn->getPdo()->beginTransaction();
            // Prevents the rows from being modified or from being selected with another shared lock
            // select * from bookings where id = ? for update
            $booking = $this->db->table('bookings')
                                ->where('movie_id', $data['movie_id'])
                                ->where('screen_id', $data['screen_id'])
                                ->where('seat_id', $data['seat_id'])
                                ->lockForUpdate()->first();
            // Notice about aquire lock
            $this->log->info('Lock on empty record is aquired:', $data);
            // Waited long enough for other incoming request to run
            sleep($this->wait);
            // If booking is not existing and this seat is free, book it !
            if (is_null($booking)) {
                // Insert new booking
                $data = $this->addTimestamp($data);
                $this->log->info('Lock for insert:', $data);
                $id = $this->db->table('bookings')->insertGetId($data);
                /* Commit all changes into database */
                $conn->getPdo()->commit();
                return $this->apiResponse($response, self::HTTP_OK, ['id' => $id, 'request' => $data], "Booking is created!");
            }
        } catch (Exception $e) {
            /* Rollback if failover */
            $conn->getPdo()->rollback();
            return $this->apiResponse($response, self::HTTP_LOCKED, [], $e->getMessage());
        }
        return $this->apiResponse($response, self::HTTP_NOT_ACCEPTABLE, [], "Booking is not accepted!");
    }

    /* Use database shared lock technique */
    public function databaseSharedLock(Request $request, Response $response, $args)
    {
        $data = $this->parse($request);
        // Validate request payload
        if ($data instanceof Exception) {
            return $this->apiResponse($response, self::HTTP_BAD_REQUEST, [], $data->getMessage());
        }
        $conn = $this->db->getConnection();
        try {
            /* Start new Illuminate database transaction */
            $conn->getPdo()->beginTransaction();
            // Prevents the rows from being modified or from being selected with another shared lock
            // select * from screens where id = ? for update
            // $this->db->table('screens')->where('id', $data['screen_id'])->lockForUpdate()->get();
            $booking = $this->db->table('bookings')
                     ->where('user_id', $data['user_id'])
                     ->where('movie_id', $data['movie_id'])
                     ->where('screen_id', $data['screen_id'])
                     ->where('seat_id', $data['seat_id'])
                     ->sharedLock()->first();
            // var_dump($booking);
            // Check for free seat
            // $booking = $this->db->table('bookings')->select('id')
            //                     ->where('user_id', $data['user_id'])
            //                     ->where('screen_id', $data['screen_id'])
            //                     ->where('seat_id', $data['seat_id'])
            //                     ->first();
            $booking = null;
            // If seat is free, book this seat
            if (is_null($booking)) {
                // Waited long enough for other incoming request to run
                sleep($this->wait);
                // Create new booking
                $id = $this->db->table('bookings')->insertGetId($data);
                /* Commit all changes into database */
                $conn->getPdo()->commit();
                return $this->apiResponse($response, self::HTTP_OK, ['id' => $id, 'request' => $data], "Booking is created!");
            }
        } catch (Exception $e) {
            /* Rollback if failover */
            $conn->getPdo()->rollback();
            return $this->apiResponse($response, self::HTTP_LOCKED, [], $e->getMessage());
        }
        return $this->apiResponse($response, self::HTTP_NOT_ACCEPTABLE, [], "Booking is not accepted!");
    }

    /* Use memcached lock technique */
    public function memcachedLock(Request $request, Response $response, $args)
    {
        $response->getBody()->write("Memcached Lock!");
        return $response;
    }

    /* Use redis lock technique */
    public function redisLock(Request $request, Response $response, $args) {
        $redis = new Redis();
        $redis->connect('redis', 6379);
        $response->getBody()->write("Redis Lock!");
        return $response;
    }

    /* Use rabbitmq lock technique */
    public function rabbitmqLock(Request $request, Response $response, $args)
    {
        $response->getBody()->write("RabbitMQ Lock!");
        return $response;
    }
}
