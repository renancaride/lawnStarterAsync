<?php

namespace App\modules\Report;

use App\Team;
use App\User;
use App\Classes;
use App\Profile;
use App\Companie;
use App\Timeline;
use App\Course;
use App\Product;
use Carbon\Carbon;
use App\TeamStudent;
use App\ProfileCompany;
use App\Http\Traits\ProfilesTrait;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\JoinClause;
use App\ReportRequest;
use stdClass;

class PerformanceStudent
{
    use ProfilesTrait;

    private $filter;

    public function setFilter(array $filterArray)
    {
        $this->filter = $filterArray;
        return $this;
    }

    /**
     * Gera os dados do relatório solicitado
     * As colunas retornadas variam de acordo com o layout
     *
     * @param User $loggedUser
     * @param String $report_layout
     * @return Collection
     */
    public function performanceStudentsReport(User $loggedUser, String $report_layout, int $reportRequestId): Collection
    {
        $performance = new Collection();

        /** @var $filters **/
        $companyId = array_get($this->filter, 'companyId');
        $teamId = array_get($this->filter, 'teamId');
        $productId = array_get($this->filter, 'productId');
        $courseId = array_get($this->filter, 'courseId');
        $classId = array_get($this->filter, 'classId');
        $profileId = array_get($this->filter, 'profileId');
        $shouldConsiderSchedule = !!array_get($this->filter, 'schedules');

        $fieldsToSelect = [
            "users.id",
            "users.name",
            "profiles.name as user_profile",
            "user_details.cargo as role",
            "user_details.setor as department",
            "users.name AS user_name",
            "user_details.celular",
            "user_details.phone",
            "teams.companie_id",
            'team_students.expired_at as team_student_expired_at',
            "companies.name as empresa",
            "user_details.empresa as company",
            "user_details.filial as subsidiary",
            "users.email as user_email",
            "profiles.name",
            "user_details.cpf",
            "users.last_login AS user_last_access"
        ];

        if ($report_layout === config('customs.reports.performance_students.layout_melissa')) {
            $fieldsToSelect = [
                "users.id",
                "users.name",
                "profiles.name as user_profile",
                "user_details.cargo as role",
                "user_details.setor as department",
                "users.name AS user_name",
                "user_details.celular",
                "user_details.phone",
                "user_details.empresa",
                "user_details.empresa as company",
                "user_details.filial as subsidiary",
                "users.email as user_email",
                "profiles.name",
                "user_details.cpf",
                "users.last_login AS user_last_access"
            ];
        }

        if (isset($fieldsToSelect)) {
            $allUsersQuery = User::query()
                ->select($fieldsToSelect)
                ->join("profiles", function (JoinClause $join) {
                    $join->on("profiles.id", "=", "users.profile_id")
                        ->where('profiles.status', config('customs.status_ativo'));
                });

            if ($report_layout === config('customs.reports.performance_students.layout_default')) {
                $allUsersQuery
                    ->join('team_students', function ($j) {
                        $j->on('team_students.user_id', 'users.id')
                            ->where('team_students.status', config('customs.status_ativo'));
                    })
                    ->join('teams', function ($j) {
                        $j->on('teams.id', 'team_students.team_id')
                            ->where('teams.status', config('customs.status_ativo'));
                    })
                    ->join('companies', function ($j) {
                        $j->on('teams.companie_id', 'companies.id')
                            ->where('companies.status', config('customs.status_ativo'));
                    })
                    ->groupBy(['users.id', 'teams.companie_id']);
            }

            $allUsersQuery
                ->leftJoin('user_details', 'users.id', 'user_details.user_id')
                ->where("users.user_type", config('customs.user_type_student'))
                ->where("users.status", config('customs.status_ativo'))
                ->where("profiles.status", config('customs.status_ativo'));

            /* Filtro por profile */
            if (isset($profileId)) {
                $allUsersQuery->whereIn('users.profile_id', $profileId);
            }

            $teamUsers = null;
            if (isset($teamId)) {
                $teamId = is_string($teamId) ? [$teamId] : $teamId;
                $teamUsers = TeamStudent::select('team_students.user_id')->whereIn('team_id', $teamId);
            } elseif (isset($companyId)) {
                $teamUsers = TeamStudent::select('team_students.user_id')->whereIn(
                    'team_id',
                    Team::whereIn('companie_id', $companyId)->pluck('id')
                );
            }

            // Se não for o layout padrão (ex: melissa), trata o status do team e company
            if ($report_layout !== config('customs.reports.performance_students.layout_default')) {
                $teamUsers
                    ->join('teams', 'teams.id', '=', 'team_students.team_id')
                    ->where('teams.status', config('customs.status_ativo'))
                    ->join('companies', 'companies.id', '=', 'teams.companie_id')
                    ->where('companies.status', config('customs.status_ativo'));
            }

            $allUsers = !$teamUsers ?
                $allUsersQuery->where("users.companie_id", $loggedUser->companie_id)->get() :
                $allUsersQuery->whereRaw('`users`.`id` IN (' . $teamUsers->toSql() . ')', $teamUsers->getBindings())->get();

            foreach ($allUsers as $user) {
                /** @var $userClasses Builder * */
                $userClasses = Classes::query()
                    ->select("classes.id", "classes.name", "teams.companie_id", "team_students.user_id")
                    ->join("courses", function (JoinClause $join) {
                        $join->on("courses.id", "classes.course_id")->where(
                            "courses.status",
                            config('customs.status_ativo')
                        );
                    })
                    ->leftJoin("topics", function (JoinClause $join) {
                        $join->on("topics.id", "classes.topic_id")->where(
                            "topics.status",
                            config('customs.status_ativo')
                        );
                    })
                    ->join("product_courses", function (JoinClause $join) {
                        $join->on("product_courses.course_id", "courses.id")->where(
                            "product_courses.status",
                            config('customs.status_ativo')
                        );
                    })
                    ->join("products", function (JoinClause $join) {
                        $join->on("products.id", "product_courses.product_id")->where(
                            "products.status",
                            config('customs.status_ativo')
                        );
                    })
                    ->join("team_products", function (JoinClause $join) {
                        $join->on("team_products.product_id", "products.id")->where(
                            "team_products.status",
                            config('customs.status_ativo')
                        );
                    });

                if ($report_layout === config('customs.reports.performance_students.layout_default')) {
                    $userClasses->join("teams", function (JoinClause $join) use ($user) {
                        $join->on("teams.id", "team_products.team_id")->where(
                            "teams.status",
                            config('customs.status_ativo')
                        )->where('teams.companie_id', $user->companie_id);
                    });
                } else {
                    $userClasses->join("teams", function (JoinClause $join) {
                        $join->on("teams.id", "team_products.team_id")->where(
                            "teams.status",
                            config('customs.status_ativo')
                        );
                    });
                }

                $userClasses
                    ->join("team_students", function (JoinClause $join) {
                        $join
                        ->on("team_students.team_id", "teams.id")
                        ->where("team_students.status", config('customs.status_ativo'))
                        ->where('team_students.teacher', config('customs.status_inativo'));
                    })
                    ->where("team_students.user_id", $user->id)
                    ->where("classes.status", config('customs.status_ativo'))
                    ->distinct();

                if ($shouldConsiderSchedule) {
                    $userClasses = $userClasses->addSelect(
                        DB::raw('scheduleCourse.id as scheduleCourseID'),
                        DB::raw('scheduleTopic.id as scheduleTopicID'),
                        DB::raw('scheduleClasses.id as scheduleClassesID'),
                        DB::raw('(
                        CASE
                            WHEN scheduleCourse.id IS NOT NULL
                                THEN 1
                            WHEN scheduleTopic.id IS NOT NULL
                                THEN 1
                            WHEN scheduleClasses.id IS NOT NULL
                                THEN 1
                            ELSE
                                0
                        END ) AS scheduleIF')
                    );

                    $userClasses->leftJoin(
                        DB::raw('schedule AS scheduleCourse'),
                        function (JoinClause $join) {
                            $join->whereRaw('scheduleCourse.team_id = team_students.team_id')
                                ->whereRaw('scheduleCourse.product_id = products.id')
                                ->whereRaw('scheduleCourse.course_id = courses.id')
                                ->whereNull('scheduleCourse.topic_id')
                                ->whereNull('scheduleCourse.class_id')
                                ->whereNull('scheduleCourse.activity_id')
                                ->whereRaw('
                                    CASE
                                        WHEN scheduleCourse.date_start IS NOT NULL AND scheduleCourse.date_end IS NULL
                                            THEN scheduleCourse.date_start > NOW()
                                        WHEN scheduleCourse.date_start IS NOT NULL AND scheduleCourse.date_end IS NOT NULL AND scheduleCourse.date_start > NOW() AND scheduleCourse.date_end > NOW()
                                            THEN !(scheduleCourse.date_end < NOW())
                                        WHEN scheduleCourse.date_start IS NOT NULL AND scheduleCourse.date_end IS NOT NULL AND scheduleCourse.date_start > NOW()  AND scheduleCourse.date_end < NOW()
                                            THEN scheduleCourse.date_end < NOW()
                                        WHEN scheduleCourse.date_start IS NOT NULL AND scheduleCourse.date_end IS NOT NULL AND scheduleCourse.date_start < NOW()
                                            THEN scheduleCourse.date_end < NOW()
                                    END');
                        }
                    )
                    ->leftJoin(
                        DB::raw('schedule AS scheduleTopic'),
                        function (JoinClause $join) {
                            $join->whereRaw('scheduleTopic.team_id = team_students.team_id')
                                ->whereRaw('scheduleTopic.product_id = products.id')
                                ->whereRaw('scheduleTopic.course_id = courses.id')
                                ->whereRaw('scheduleTopic.topic_id = topics.id')
                                ->whereNull('scheduleTopic.class_id')
                                ->whereNull('scheduleTopic.activity_id')
                                ->whereRaw('
                                    CASE
                                        WHEN scheduleTopic.date_start IS NOT NULL AND scheduleTopic.date_end IS NULL
                                        THEN scheduleTopic.date_start > NOW()
                                        WHEN scheduleTopic.date_start IS NOT NULL AND scheduleTopic.date_end IS NOT NULL AND scheduleTopic.date_start > NOW() AND scheduleTopic.date_end > NOW()
                                        THEN !(scheduleTopic.date_end < NOW())
                                        WHEN scheduleTopic.date_start IS NOT NULL AND scheduleTopic.date_end IS NOT NULL AND scheduleTopic.date_start > NOW()  AND scheduleTopic.date_end < NOW()
                                        THEN scheduleTopic.date_end < NOW()
                                        WHEN scheduleTopic.date_start IS NOT NULL AND scheduleTopic.date_end IS NOT NULL AND scheduleTopic.date_start < NOW()
                                        THEN scheduleTopic.date_end < NOW()
                                    END
                                ');
                        }
                    )
                    ->leftJoin(
                        DB::raw('schedule AS scheduleClasses'),
                        function (JoinClause $join) {
                            $join->whereRaw('scheduleClasses.team_id = team_students.team_id')
                                ->whereRaw('scheduleClasses.product_id = products.id')
                                ->whereRaw('scheduleClasses.course_id = courses.id')
                                ->whereRaw('scheduleClasses.class_id = classes.id')
                                ->whereNull('scheduleClasses.activity_id')
                                ->whereRaw('
                                    CASE
                                        WHEN scheduleClasses.date_start IS NOT NULL AND scheduleClasses.date_end IS NULL
                                        THEN scheduleClasses.date_start > NOW()
                                        WHEN scheduleClasses.date_start IS NOT NULL AND scheduleClasses.date_end IS NOT NULL AND scheduleClasses.date_start > NOW() AND scheduleClasses.date_end > NOW()
                                        THEN !(scheduleClasses.date_end < NOW())
                                        WHEN scheduleClasses.date_start IS NOT NULL AND scheduleClasses.date_end IS NOT NULL AND scheduleClasses.date_start > NOW()  AND scheduleClasses.date_end < NOW()
                                        THEN scheduleClasses.date_end < NOW()
                                        WHEN scheduleClasses.date_start IS NOT NULL AND scheduleClasses.date_end IS NOT NULL AND scheduleClasses.date_start < NOW()
                                        THEN scheduleClasses.date_end < NOW()
                                    END
                                ');
                        }
                    );
                }

                if (!$loggedUser->is_Owner_Only()) {
                    $userClasses->whereIn(
                        "teams.companie_id",
                        ProfileCompany::where('profile_companies.profile_id', $loggedUser->profile_id)
                            ->pluck('profile_companies.company_id')
                    );
                }

                if ($companyId !== null) {
                    $userClasses->whereIn("teams.companie_id", $companyId);
                }

                if ($teamId !== null) {
                    $userClasses->whereIn("teams.id", $teamId);
                }

                if ($productId !== null) {
                    $userClasses->whereIn("products.id", $productId);
                }

                if ($courseId !== null) {
                    $userClasses->whereIn("courses.id", $courseId);
                }

                if ($classId !== null) {
                    $userClasses->whereIn("classes.id", $classId);
                }

                /** Ignora os alunos que não tem aulas **/
                if ($userClasses->count() === 0) {
                    continue;
                }

                $userClasses = $userClasses->get();
                $userClassesSchedule = $userClasses;

                $company = Companie::find(
                    $userClasses->unique('companie_id')->first()->companie_id
                );

                if ($shouldConsiderSchedule) {
                    $userClasses = $userClasses->where('scheduleIF', 0);
                }

                /** @var $userTimeline Builder * */
                $userTimeline = Timeline::select("class_id", "action_id")
                    ->where("user_id", $user->id)
                    ->whereIn("action_id", [config('customs.act_class_complete'), config('customs.act_class_access')])
                    ->distinct()
                    ->whereIn("class_id", $userClasses->pluck("id"))
                    ->get();

                $totalClasses = $userClasses;
                $totalDone = $userTimeline->where("action_id", config('customs.act_class_complete'));
                $totalInProgress = $userTimeline->where("action_id", config('customs.act_class_access'));
                $totalDone = $totalDone->pluck('class_id');
                $totalStarted = $totalDone->merge($totalInProgress->pluck('class_id'));

                $userClassesNotStarted = $shouldConsiderSchedule ? $userClasses->where('scheduleIF', 0) : $userClasses;
                $userClassesNotStarted = $userClassesNotStarted->whereNotIn('id', $totalStarted->toArray());

                $profileName = $user->user_profile;
                $unityName = $user->empresa;
                $companyName = $user->company;
                $subsidiaryName = $user->subsidiary;

                if ($report_layout === config('customs.reports.performance_students.layout_melissa')
                    && strpos($user->user_profile, ' - ') !== false
                ) {
                    list($profileName, $unityName) = mb_split(' - ', $user->user_profile);
                }

                $consultsName = $report_layout === config('customs.reports.performance_students.layout_melissa')
                    ? $this->complementReportConsultants($userClasses)
                    : '';
                $franchiseeName = $report_layout === config('customs.reports.performance_students.layout_melissa')
                    ? $this->complementReportFranchisess($userClasses)
                    : '';
                $region = $company->region ? $company->region->nome : '';

                if (!empty($user->team_student_expired_at)) {
                    $user->team_student_expired_at = Carbon::parse($user->team_student_expired_at)->format('d/m/Y');
                }

                // Variaveis pra popular valores do relatório
                $arrayParams = [];

                $arrayParams['user_id']= $user->id;
                $arrayParams['company'] = "all";

                $reportCompanyId = array_get($this->filter, 'companyId');
                $reportTeamId = array_get($this->filter, 'teamId');
                if (isset($reportCompanyId)) {
                    $arrayParams['company'] = count($reportCompanyId) > 1 ? "all": array_shift($reportCompanyId);
                }
                $arrayParams['team'] = "";
                if (isset($reportTeamId)) {
                    $arrayParams['team'] = count($reportTeamId) > 1 ? "": array_shift($reportTeamId);
                }
                $url = '/report_classes?user_id='.$arrayParams['user_id'].'&company='.$arrayParams['company'].'&team='.$arrayParams['team'];

                $classes_total_with_scheduled = $totalClasses->count() + $userClassesSchedule->where('scheduleIF', 1)->count();
                $classes_completed = $totalDone->count();
                $classes_inprogress = $totalInProgress->count() - $totalDone->count();
                $class_notstarted = $userClassesNotStarted->count();
                $classes_blocked = $userClassesSchedule->where('scheduleIF', 1)->count();

                $progress_completed = 0;
                $progress_inprogress = 0;
                $progress_notstarted = 0;
                $progress_blocked = 0;

                if ($classes_total_with_scheduled > 0) {
                    $progress_completed = number_format((($classes_completed * 100) / $classes_total_with_scheduled), 2);
                    $progress_inprogress = number_format((($classes_inprogress * 100) / $classes_total_with_scheduled), 2);
                    $progress_notstarted = number_format((($class_notstarted * 100) / $classes_total_with_scheduled), 2);
                    $progress_blocked = number_format((($classes_blocked * 100) / $classes_total_with_scheduled), 2);
                }

                $progress_bar = "<div class='progress'>
                        <div class='progress-bar bg-completed' style='width: $progress_completed%'>
                            <span class='sr-only'>$progress_completed% Concluído</span>
                        </div>
                        <div class='progress-bar bg-progress' style='width: $progress_inprogress%'>
                            <span class='sr-only'>$progress_inprogress% Em andamento</span>
                        </div>
                        <div class='progress-bar bg-pending' style='width: $progress_notstarted%'>
                            <span class='sr-only'>$progress_notstarted% Não iniciado</span>
                        </div>
                        <div class='progress-bar bg-notavailable' style='width: $progress_blocked%'>
                            <span class='sr-only'>$progress_blocked% Não Disponíveis</span>
                        </div>
                    </div>";

                $performance->put($user->id.$user->empresa, (object)[
                    "id" => $user->id,
                    "user" => $user,
                    "user_name" => $user->name,
                    "team_student_expired_at" => $user->team_student_expired_at,
                    "profile_name" => $profileName,
                    "unity_name" => $unityName,
                    "company_name" => $companyName,
                    "subsidiary_name" => $subsidiaryName,
                    "consultants" => $consultsName,
                    "franchisee" => $franchiseeName,
                    "region" => $region,
                    "class_notstarted" => $class_notstarted,
                    "classes_total" => $totalClasses->count(),
                    "classes_total_with_scheduled" => $classes_total_with_scheduled,
                    "classes_completed" => $classes_completed,
                    "classes_inprogress" => $classes_inprogress,
                    "classes_blocked" => $classes_blocked,
                    "classes_unblocked" => $totalClasses->count() - $userClasses->where('scheduleIF', 1)->count(),
                    "progress_bar" => $progress_bar,
                    "progress_blocked" => $progress_blocked,
                    "progress_completed" => $progress_completed,
                    "progress_inprogress" => $progress_inprogress,
                    "progress_notstarted" => $progress_notstarted,
                    "DT_RowAttr" => (object)[
                        "url" => $url
                    ]
                ]);
            }

            $filters = compact('companyId', 'teamId', 'productId', 'courseId', 'classId', 'profileId', 'shouldConsiderSchedule');

            $included = [
                'filtersDataOriginal' => (object) $this->filter,
                'notAllowedByCompany' => $this->performanceStudentsReportNotAllowed($loggedUser, $filters)
            ];

            $this->updateToNewData($included, $reportRequestId);

            // Define uma nova chave no cache para informações extras
            $performance->put('included', (object) $included);
        }

        return $performance;
    }

    /**
     * Retorna os filtros que o usuário não possui mais permissão
     * Ignora caso o usuário seja Owner
     */
    private function performanceStudentsReportNotAllowed(User $loggedUser, array $filters): object
    {
        $response = [
            'companies' => [],
            'teams' => [],
            'products' => [],
            'courses' => [],
            'classes' => [],
            'profiles' => []
        ];

        extract($filters);

        $newCompanyId = null;
        $newTeamId = null;
        $newProductId = null;
        $newCourseId = null;

        $companiesAllowed = Companie::getCompaniesByUser(
            false,
            false,
            true,
            $loggedUser
        )
            ->pluck('companies.id')
            ->toArray();

        $companiesNotAllowed = array_diff($companyId, $companiesAllowed);

        $newCompanyId = array_intersect($companyId, $companiesAllowed);
        $newCompanyId = !empty($newCompanyId) ? $newCompanyId : [0];

        $response['companies'] = Companie::select('companies.id', 'companies.name')
            ->whereIn('companies.id', $companiesNotAllowed)
            ->groupBy('companies.id')
            ->get()
            ->toArray() ?: [];

        if (!empty($teamId)) {
            $teamsAllowed = Team::getTeamsbyCompany(
                $newCompanyId,
                null,
                'teams.id',
                true,
                $loggedUser
            )
                ->pluck('teams.id')
                ->toArray();

            $newTeamId = array_intersect($teamId, $teamsAllowed);
            $newTeamId = !empty($newTeamId) ? $newTeamId : [0];

            $response['teams'] = Team::select('teams.id', 'teams.name')
                ->whereIn('teams.id', array_diff($teamId, $teamsAllowed))
                ->groupBy('teams.id')
                ->get()
                ->toArray() ?: [];
        }

        if (!empty($productId)) {
            $productsAllowed = Product::getProductsByTeam(
                $newCompanyId,
                $newTeamId,
                'products.id',
                true,
                $loggedUser
            )
                ->pluck('products.id')
                ->toArray();

            $newProductId = array_intersect($productId, $productsAllowed);
            $newProductId = !empty($newProductId) ? $newProductId : [0];

            $response['products'] = Product::select('products.id', 'products.name')
                ->whereIn('products.id', array_diff($productId, $productsAllowed))
                ->groupBy('products.id')
                ->get()
                ->toArray() ?: [];
        }

        if (!empty($courseId)) {
            $coursesAllowed = Course::getCoursesByProduct(
                $newCompanyId,
                $newProductId,
                $newTeamId,
                'courses.id',
                [],
                true,
                $loggedUser
            )
                ->pluck('courses.id')
                ->toArray();

            $newCourseId = array_intersect($courseId, $coursesAllowed);
            $newCourseId = !empty($newCourseId) ? $newCourseId : [0];

            $response['courses'] = Course::select('courses.id', 'courses.name')
                ->whereIn('courses.id', array_diff($courseId, $coursesAllowed))
                ->groupBy('courses.id')
                ->get()
                ->toArray() ?: [];
        }

        if (!empty($classId)) {
            $classesAllowed = Classes::getClassesByCourse(
                $newCompanyId,
                $newCourseId,
                $newTeamId,
                $newProductId,
                null,
                true,
                $loggedUser
            )
                ->pluck('classes.id')
                ->toArray();

            $response['classes'] = Classes::select('classes.id', 'classes.name')
                ->whereIn('classes.id', array_diff($classId, $classesAllowed))
                ->groupBy('classes.id')
                ->get()
                ->toArray() ?: [];
        }

        /** Somente para empresas específicas (definidas no .env) **/
        if (!empty($profileId)) {
            $validProfiles = $this->getValidProfiles(
                [
                        'profileStatus' => [
                            config('customs.status_inativo'),
                            config('customs.status_ativo'),
                            config('customs.status_deletado'),
                        ],
                        'performanceFilter' => true,
                        'companiesIds' => $newCompanyId
                    ],
                [],
                ['profiles.id'],
                $loggedUser
            )
                ->whereIn('profiles.id', $profileId)
                ->pluck('profiles.id')
                ->toArray();

            $profilesNotAllowed = array_diff($profileId, $validProfiles);

            $response['profiles'] = Profile::select('profiles.id', 'profiles.name')
                ->whereIn('profiles.id', $profilesNotAllowed)
                ->groupBy('profiles.id')
                ->get()
                ->toArray() ?: [];
        }

        return json_decode(json_encode($response));
    }

    /**
     * Atualiza no banco de dados
     *
     * @param array $included
     * @return boolean
     */
    private function updateToNewData(array $included, int $reportRequestId): bool
    {
        $excludedFilters = (array) $included['notAllowedByCompany'];

        if (empty($excludedFilters)) {
            return false;
        }

        $reportRequest = ReportRequest::find($reportRequestId);

        if (!$reportRequest) {
            return false;
        }

        $newData = $this->removeExcludedFromFilters($included['filtersDataOriginal'], $excludedFilters);

        if (!$newData) {
            return false;
        }

        $reportRequest->data = json_encode($newData);

        return $reportRequest->save();
    }

    /**
     * Remove os filtros não permitidos da lista de permitidos
     *
     * @param array $data
     * @return array|null
     */
    private function removeExcludedFromFilters(object $reportFilters, array $excludedFilters): object
    {
        $newReporFilters = new stdClass;
        
        foreach ($reportFilters as $filterName => $value) {
            switch ($filterName) {
                case 'companyId':
                    $excludedKeyName = 'companies';
                    break;
                case 'teamId':
                    $excludedKeyName = 'teams';
                    break;
                case 'productId':
                    $excludedKeyName = 'products';
                    break;
                case 'courseId':
                    $excludedKeyName = 'courses';
                    break;
                case 'classId':
                    $excludedKeyName = 'classes';
                    break;
                case 'profileId':
                    $excludedKeyName = 'profiles';
                    break;
                
                default:
                    $excludedKeyName = '__ISNULL';
                    break;
            }

            $excludedFilter = $excludedFilters[$excludedKeyName] ?? null;

            if (!is_array($value) || empty($value) || !is_array($excludedFilter) || empty($excludedFilter)) {
                $newReporFilters->{$filterName} = $value;
                continue;
            }

            // Separa os ids dos itens "sem permissão"
            $excludedIdMap = array_map(function ($excludedFilterValue) {
                return $excludedFilterValue->id;
            }, $excludedFilter);
            
            // Remove os itens que não possui permissão da array de filtros
            $newReporFilters->{$filterName} = array_filter($value, function ($id) use ($excludedIdMap) {
                return !in_array($id, $excludedIdMap);
            });
        }
        
        return $newReporFilters;
    }

    private function complementReportConsultants($userClasses)
    {
        /** Recuperando o Consultor **/
        $consultants = Profile::select('profiles.name')
            ->join('profile_companies', function (JoinClause $join) {
                $join->on('profiles.id', '=', 'profile_companies.profile_id')
                    ->where('profile_companies.status', config('customs.status_ativo'));
            })
            ->whereRaw('UPPER(profiles.name) like (?)', ["CONSULTOR - %"])
            ->where('profiles.status', config('customs.status_ativo'))
            ->where('profile_companies.company_id', $userClasses->unique('companie_id')->first()->companie_id)
            ->first();

        $consultsName = '';
        if ($consultants && strpos($consultants->name, ' - ') !== false) {
            list($consult, $consultsName) = mb_split(' - ', $consultants->name);
        }
        return $consultsName;
    }


    private function complementReportFranchisess($userClasses)
    {
        /** Recuperando os Franquiados **/
        $franchisees = Profile::select('profiles.name')
            ->join('profile_companies', function (JoinClause $join) {
                $join->on('profiles.id', '=', 'profile_companies.profile_id')
                    ->where('profile_companies.status', config('customs.status_ativo'));
            })
            ->whereRaw('UPPER(profiles.name) like (?)', ["FRANQUEADO - %"])
            ->where('profiles.status', config('customs.status_ativo'))
            ->where('profile_companies.company_id', $userClasses->unique('companie_id')->first()->companie_id)
            ->get();

        $franchiseeName = '';
        foreach ($franchisees as $franchisee) {
            list($_franc, $_franchisseName) = mb_split(' - ', $franchisee->name);
            $franchiseeName .= "{$_franchisseName}, ";
        }
        $franchiseeName = substr($franchiseeName, 0, -2);

        return $franchiseeName;
    }
}
