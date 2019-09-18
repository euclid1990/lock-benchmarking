<?php

use Carbon\Carbon;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

require __DIR__ . '/src/kernel.php';

/* Implement Init command console */
class Init extends Command
{
    protected $db;

    protected $migrator;

    protected $log;

    protected $config;

    protected $output;

    protected $numberUser = 10;

    protected $numberscreen = 3;

    protected $numberSeat = 10;

    public function __construct($container)
    {
        parent::__construct();
        $this->db = $container->get('db');
        $this->log = $container->get('log');
        $this->config = $container->get('config');
        $this->migrator = $container->get('migrator');
        $this->faker = Faker\Factory::create();
    }

    protected function configure()
    {
        $this->setName('init')
            ->setDescription('Initialize database')
            ->setHelp('Create database structures and import fake data')
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_OPTIONAL,
                'Refresh schema',
                false
            )
            ->addOption(
                'seed',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seed database records',
                false
            )
            ->addOption(
                'booking',
                null,
                InputOption::VALUE_OPTIONAL,
                'Seed booking records',
                false
            );
    }

    protected function setOutput(InputInterface $input, OutputInterface $output)
    {
        $this->output = new OutputStyle($input, $output);
    }

    protected function note(string $message)
    {
        if ($this->output) {
            $this->output->writeln($message);
        }
    }

    protected function clear()
    {
        // Clear application log file
        $logFile = $this->config->get('paths.log');
        if (file_exists($logFile)) {
            unlink($logFile);
        }
    }

    protected function migrate(bool $refresh)
    {
        $paths = $this->config->get('paths.migrations');
        if (!$this->migrator->repositoryExists()) {
            // Prepare the migration database for running
            $this->migrator->getRepository()->createRepository();
        }
        if ($refresh) {
            // Set output style message log
            $this->migrator->setOutput($this->output)->reset($paths);
        }
        $this->migrator->setOutput($this->output)->run($paths);
    }

    protected function insertUser()
    {
        $users = [];
        foreach (range(1, $this->numberUser) as $i) {
            $users[] = [
                'name' => $this->faker->name,
                'email' => $this->faker->unique()->safeEmail,
            ];
        }
        $this->db->table('users')->insert($users);
        $this->note('<info>Seeded: Users data</info>');
    }

    protected function insertSeat($screenId, $screenCode)
    {
        $digits = (int)(log($this->numberSeat, 10) + 1);
        $seats = [];
        foreach (range(1, $this->numberSeat) as $i) {
            $seats[] = [
                'screen_id' => $screenId,
                'code' => $screenCode . sprintf("%0${digits}d", $i),
            ];
        }
        $this->db->table('seats')->insert($seats);
    }

    protected function insertScreen()
    {
        $screenCode = 'A';
        $conn = $this->db->getConnection();
        foreach (range(1, $this->numberscreen) as $i) {
            try {
                /* Start new Illuminate database transaction */
                $conn->getPdo()->beginTransaction();
                /* Insert ciname information */
                $this->db->table('screens')->insert(['code' => $screenCode]);
                $screenId = $conn->getPdo()->lastInsertId();
                /* Insert screen seat rows */
                $this->insertSeat($screenId, $screenCode);
                /* Commit all changes into database */
                $conn->getPdo()->commit();
            } catch (Exception $e) {
                $this->note($e);
                /* Rollback if failover */
                $conn->getPdo()->rollback();
            }
            $screenCode++;
        }
        $this->note('<info>Seeded: Screens data</info>');
        $this->note('<info>Seeded: Seats data</info>');
    }

    protected function insertMovie()
    {
        $startedAt = Carbon::now();
        $endedAt = Carbon::now()->addHour();
        $movies = [
            [ 'title' => 'Spider-Man: Far From Home', 'code' => 'SM2020','started_at' => $startedAt, 'ended_at' => $endedAt ]
        ];
        $this->db->table('movies')->insert($movies);
        $this->note('<info>Seeded: Movies data</info>');
    }

    protected function insertBooking()
    {
        $now = Carbon::now();
        $movieId = $this->db->table('movies')->select('id')->pluck('id')->first();
        $screenIds = $this->db->table('screens')->select('id')->get()->pluck('id');
        foreach ($screenIds as $screenId) {
            $seatIds = $this->db->table('seats')->select('id')->where('screen_id', $screenId)->get()->pluck('id');
            foreach ($seatIds as $seatId) {
                $bookings[] = [
                    'user_id' => null,
                    'screen_id' => $screenId,
                    'seat_id' => $seatId,
                    'movie_id' => $movieId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->db->table('bookings')->insert($bookings);
        $this->note('<info>Seeded: Bookings data</info>');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setOutput($input, $output);
        try {
            /* Output start log */
            $this->log->info('Command starting');
            /* Check command argument */
            $refresh = $input->getOption('refresh');
            $refresh =  $refresh !== false;
            $seed = $input->getOption('seed');
            $seed = $seed !== false;
            $booking = $input->getOption('booking');
            $booking = $booking !== false;

            /* Run the schema migrations */
            $this->migrate($refresh);

            /* Run seed database */
            if ($seed) {
                /* Remove inserted data in users/seats/screens/movies tables */
                $this->db->table('users')->delete();
                $this->db->table('seats')->delete();
                $this->db->table('screens')->delete();
                $this->db->table('movies')->delete();
                /* Insert user master data */
                $this->insertUser($output);
                /* Insert screen master data */
                $this->insertScreen($output);
                /* Insert movie master data */
                $this->insertMovie($output);
                if ($booking) {
                    /* Remove inserted data in bookings tables */
                    $this->db->table('bookings')->delete();
                    /* Insert booking master data */
                    $this->insertBooking($output);
                }
            }

            /* Output complete log */
            $this->log->info('Command completed');
        } catch (Throwable $th) {
            $this->log->error($th->getMessage() . "\r\n" .$th->getTraceAsString());
        }

        $this->clear();
    }
}

/* Bootstrap application */
$app = boot();

/* Get the container */
$container = $app->getContainer();

/* Init console application */
$console = new Application();

/* Add commands to console */
$console->add(new Init($container));

/* Run console */
$console->run();
