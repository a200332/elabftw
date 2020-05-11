<?php
/**
 * @author Nicolas CARPi <nicolas.carpi@curie.fr>
 * @copyright 2012 Nicolas CARPi
 * @see https://www.elabftw.net Official website
 * @license AGPL-3.0
 * @package elabftw
 */
declare(strict_types=1);

namespace Elabftw\Models;

use Elabftw\Elabftw\Db;
use Elabftw\Elabftw\Tools;
use Elabftw\Exceptions\IllegalActionException;
use Elabftw\Exceptions\ImproperActionException;
use Elabftw\Services\Check;
use Elabftw\Services\Email;
use Elabftw\Services\Filter;
use Elabftw\Services\UsersHelper;
use function in_array;
use PDO;
use function setcookie;

/**
 * Users
 */
class Users
{
    /** @var bool $needValidation flag to check if we need validation or not */
    public $needValidation = false;

    /** @var array $userData what you get when you read() */
    public $userData = array();

    /** @var Db $Db SQL Database */
    protected $Db;

    /** @var int $team */
    private $team;

    /**
     * Constructor
     *
     * @param int|null $userid
     */
    public function __construct(?int $userid = null, ?int $team = null)
    {
        $this->Db = Db::getConnection();
        $this->team = 0;
        if ($team !== null) {
            $this->team = $team;
        }
        if ($userid !== null) {
            $this->populate($userid);
        }
    }

    /**
     * Populate userData property
     *
     * @param int $userid
     */
    public function populate(int $userid): void
    {
        Check::idOrExplode($userid);
        $this->userData = $this->read($userid);
        $this->userData['team'] = $this->team;
    }

    /**
     * Create a new user. If no password is provided, it's because we create it from SAML.
     *
     * @param string $email
     * @param array $teams
     * @param string $firstname
     * @param string $lastname
     * @param string $password
     * @param int|null $group
     * @param bool $forceValidation used when user is created from SAML login
     * @return int the new userid
     */
    public function create(string $email, array $teams, string $firstname = '', string $lastname = '', string $password = '', ?int $group = null, bool $forceValidation = false): int
    {
        $Config = new Config();
        $Teams = new Teams($this);
        $UsersHelper = new UsersHelper();

        // validate teams
        $Teams->validateTeams($teams);
        // check for duplicate of email
        if ($this->isDuplicateEmail($email)) {
            throw new ImproperActionException(_('Someone is already using that email address!'));
        }

        if ($password !== '') {
            Check::passwordLength($password);
        }

        $firstname = \filter_var($firstname, FILTER_SANITIZE_STRING);
        $lastname = \filter_var($lastname, FILTER_SANITIZE_STRING);

        // Create salt
        $salt = \hash('sha512', \bin2hex(\random_bytes(16)));
        // Create hash
        $passwordHash = \hash('sha512', $salt . $password);

        // Registration date is stored in epoch
        $registerDate = \time();

        // get the group for the new user
        if ($group === null) {
            $teamId = $Teams->getTeamIdFromNameOrOrgid((string) $teams[0]);
            $group = $UsersHelper->getGroup($teamId);
        }

        // will new user be validated?
        $validated = $Config->configArr['admin_validate'] && ($group === 4) ? 0 : 1;
        if ($forceValidation) {
            $validated = 1;
        }

        // make sure that all the teams in which the user will be are created/exist
        // this might throw an exception if the team doesn't exist and we can't create it on the fly
        // the $teamIdArr is an array of teams ID
        $teamIdArr = $Teams->validateTeams($teams);

        $sql = 'INSERT INTO users (
            `email`,
            `password`,
            `firstname`,
            `lastname`,
            `usergroup`,
            `salt`,
            `register_date`,
            `validated`,
            `lang`
        ) VALUES (
            :email,
            :password,
            :firstname,
            :lastname,
            :usergroup,
            :salt,
            :register_date,
            :validated,
            :lang);';
        $req = $this->Db->prepare($sql);

        $req->bindParam(':email', $email);
        $req->bindParam(':salt', $salt);
        $req->bindParam(':password', $passwordHash);
        $req->bindParam(':firstname', $firstname);
        $req->bindParam(':lastname', $lastname);
        $req->bindParam(':register_date', $registerDate);
        $req->bindParam(':validated', $validated, PDO::PARAM_INT);
        $req->bindParam(':usergroup', $group, PDO::PARAM_INT);
        $req->bindValue(':lang', $Config->configArr['lang']);
        $this->Db->execute($req);
        $userid = $this->Db->lastInsertId();

        // now add the user to the team
        $Teams->addUserToTeams($userid, $teamIdArr);
        if ($validated === 0) {
            $Email = new Email($Config, $this);
            $Email->alertAdmin($teamIdArr[0]);
            // set a flag to show correct message to user
            // TODO put in session?
            $this->needValidation = true;
        }
        return $userid;
    }

    /**
     * Check we have not a duplicate email in DB
     *
     * @param string $email
     * @return bool true if there is a duplicate
     */
    public function isDuplicateEmail(string $email): bool
    {
        $sql = 'SELECT email FROM users WHERE email = :email AND archived = 0';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':email', $email);
        $this->Db->execute($req);

        return (bool) $req->rowCount();
    }

    /**
     * Get info about a user
     *
     * @param int $userid
     * @return array
     */
    public function read(int $userid): array
    {
        $sql = "SELECT users.*, CONCAT(users.firstname, ' ', users.lastname) AS fullname,
            groups.can_lock, groups.is_admin, groups.is_sysadmin FROM users
            LEFT JOIN `groups` ON groups.id = users.usergroup
            WHERE users.userid = :userid";
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $userid, PDO::PARAM_INT);
        $this->Db->execute($req);
        $res = $req->fetch();
        if ($res === false) {
            throw new IllegalActionException('User not found.');
        }

        return $res;
    }

    /**
     * Get users matching a search term for consumption in autocomplete
     *
     * @param string $term
     * @return array
     */
    public function lookFor(string $term): array
    {
        $usersArr = $this->readFromQuery($term);
        $res = array();
        foreach ($usersArr as $user) {
            $res[] = $user['userid'] . ' - ' . $user['fullname'];
        }
        return $res;
    }

    /**
     * Select by email
     *
     * @param string $email
     * @return void
     */
    public function populateFromEmail(string $email): void
    {
        $sql = 'SELECT userid
            FROM users
            WHERE email = :email AND archived = 0 LIMIT 1';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':email', $email);
        $this->Db->execute($req);
        $res = $req->fetchColumn();
        if ($res === false) {
            throw new ImproperActionException(_('Email not found in database!'));
        }
        $this->populate((int) $res);
    }

    /**
     * Search users based on query. It searches in email, firstname, lastname or team name
     *
     * @param string $query the searched term
     * @param bool $teamFilter toggle between sysadmin/admin view
     * @return array
     */
    public function readFromQuery(string $query, bool $teamFilter = false): array
    {
        $teamFilterSql = '';
        if ($teamFilter) {
            $teamFilterSql = 'AND users2teams.teams_id = :team';
        }

        // NOTE: previously, the ORDER BY started with the team, but that didn't work
        // with the DISTINCT, so it was removed.
        $sql = "SELECT DISTINCT users.userid,
            users.firstname, users.lastname, users.email,
            users.validated, users.usergroup, users.archived, users.last_login,
            CONCAT(users.firstname, ' ', users.lastname) AS fullname
            FROM users
            CROSS JOIN users2teams ON (users2teams.users_id = users.userid " . $teamFilterSql . ')
            WHERE (users.email LIKE :query OR users.firstname LIKE :query OR users.lastname LIKE :query)
            ORDER BY users.usergroup ASC, users.lastname ASC';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':query', '%' . $query . '%');
        if ($teamFilter) {
            $req->bindValue(':team', $this->userData['team']);
        }
        $this->Db->execute($req);

        $res = $req->fetchAll();
        if ($res === false) {
            return array();
        }
        return $res;
    }

    /**
     * Read all users from the team
     *
     * @param int|null $validated
     * @return array
     */
    public function readAllFromTeam(?int $validated = null): array
    {
        $valSql = '';
        if (is_int($validated)) {
            $valSql = ' users.validated = :validated AND ';
        }

        $sql = "SELECT DISTINCT users.userid, CONCAT (users.firstname, ' ', users.lastname) AS fullname,
            users.email,
            users.phone,
            users.cellphone,
            users.website,
            users.skype
            FROM users
            CROSS JOIN users2teams ON (users2teams.users_id = users.userid AND users2teams.teams_id = :team)
            LEFT JOIN teams ON (teams.id = :team)
            WHERE " . $valSql . ' teams.id = :team';
        $req = $this->Db->prepare($sql);

        if (is_int($validated)) {
            $req->bindValue(':validated', $validated);
        }
        $req->bindValue(':team', $this->team);
        $this->Db->execute($req);

        $res = $req->fetchAll();
        if ($res === false) {
            return array();
        }
        return $res;
    }

    /**
     * Get email for every single user
     *
     * @param bool $fromTeam
     * @return array
     */
    public function getAllEmails(bool $fromTeam = false): array
    {
        $sql = 'SELECT email FROM users WHERE validated = 1 AND archived = 0';
        if ($fromTeam) {
            $sql .= ' AND team = :team';
        }
        $req = $this->Db->prepare($sql);
        if ($fromTeam) {
            $req->bindParam(':team', $this->userData['team'], PDO::PARAM_INT);
        }
        $this->Db->execute($req);

        $res = $req->fetchAll();
        if ($res === false) {
            return array();
        }
        return $res;
    }

    /**
     * Update user from the editusers template
     *
     * @param array $params POST
     * @return void
     */
    public function update(array $params): void
    {
        $firstname = Filter::sanitize($params['firstname']);
        $lastname = Filter::sanitize($params['lastname']);
        $email = filter_var($params['email'], FILTER_SANITIZE_EMAIL);
        $UsersHelper = new UsersHelper();

        // check email is not already in db
        $usersEmails = $this->getAllEmails();
        $emailsArr = array();
        // get all emails in a nice array
        foreach ($usersEmails as $user) {
            $emailsArr[] = $user['email'];
        }

        // now make sure the new email is not already used by someone
        // it's okay if it's the same email as before though
        if (in_array($email, $emailsArr, true) && $email !== $this->userData['email']) {
            throw new ImproperActionException('Email is already used by non archived user!');
        }

        $validated = 0;
        if ($params['validated'] == 1) {
            $validated = 1;
        }

        $usergroup = Check::id((int) $params['usergroup']);

        if (\mb_strlen($params['password']) > 1) {
            $this->updatePassword($params['password']);
        }

        $sql = 'UPDATE users SET
            firstname = :firstname,
            lastname = :lastname,
            email = :email,
            usergroup = :usergroup,
            validated = :validated
            WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':firstname', $firstname);
        $req->bindParam(':lastname', $lastname);
        $req->bindParam(':email', $email);
        $req->bindParam(':validated', $validated);
        $req->bindParam(':usergroup', $usergroup);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Update preferences from user control panel
     *
     * @param array $params
     * @return void
     */
    public function updatePreferences(array $params): void
    {
        // LIMIT
        $new_limit = Check::limit((int) $params['limit']);

        // DISPLAY SIZE
        $new_display_size = Check::displaySize($params['display_size']);

        // ORDER BY
        $new_orderby = null;
        $whitelistOrderby = array(null, 'cat', 'date', 'title', 'comment', 'lastchange');
        if (isset($params['orderby']) && in_array($params['orderby'], $whitelistOrderby, true)) {
            $new_orderby = $params['orderby'];
        }

        // SORT
        $new_sort = 'desc';
        if (isset($params['sort']) && ($params['sort'] === 'asc' || $params['sort'] === 'desc')) {
            $new_sort = $params['sort'];
        }

        // LAYOUT
        $new_layout = Filter::onToBinary($params['single_column_layout'] ?? '');

        // KEYBOARD SHORTCUTS
        // only take first letter
        $new_sc_create = $params['sc_create'][0];
        if (!ctype_alpha($new_sc_create)) {
            $new_sc_create = 'c';
        }
        $new_sc_edit = $params['sc_edit'][0];
        if (!ctype_alpha($new_sc_edit)) {
            $new_sc_edit = 'e';
        }
        $new_sc_submit = $params['sc_submit'][0];
        if (!ctype_alpha($new_sc_submit)) {
            $new_sc_submit = 's';
        }
        $new_sc_todo = $params['sc_todo'][0];
        if (!ctype_alpha($new_sc_todo)) {
            $new_sc_todo = 't';
        }

        // SHOW TEAM
        $new_show_team = Filter::onToBinary($params['show_team'] ?? '');
        // SHOW TEAM TEMPLATES
        $new_show_team_template = Filter::onToBinary($params['show_team_template'] ?? '');
        // CJK FONTS
        $new_cjk_fonts = Filter::onToBinary($params['cjk_fonts'] ?? '');
        // PDF/A
        $new_pdfa = Filter::onToBinary($params['pdfa'] ?? '');
        // PDF format
        $new_pdf_format = 'A4';
        $formatsArr = array('A4', 'LETTER', 'ROYAL');
        if (in_array($params['pdf_format'], $formatsArr, true)) {
            $new_pdf_format = $params['pdf_format'];
        }

        // USE MARKDOWN
        $new_use_markdown = Filter::onToBinary($params['use_markdown'] ?? '');
        // INCLUDE FILES IN PDF
        $new_inc_files_pdf = Filter::onToBinary($params['inc_files_pdf'] ?? '');
        // CHEM EDITOR
        $new_chem_editor = Filter::onToBinary($params['chem_editor'] ?? '');
        // JSON EDITOR
        $new_json_editor = Filter::onToBinary($params['json_editor'] ?? '');
        // LANG
        $new_lang = 'en_GB';
        if (isset($params['lang']) && array_key_exists($params['lang'], Tools::getLangsArr())) {
            $new_lang = $params['lang'];
        }

        // DEFAULT READ/WRITE
        $new_default_read = Check::visibility($params['default_read'] ?? 'team');
        $new_default_write = Check::visibility($params['default_write'] ?? 'team');

        // Signature pdf
        // only use cookie here because it's temporary code
        if (isset($params['pdf_sig']) && $params['pdf_sig'] === 'on') {
            setcookie('pdf_sig', '1', time() + 2592000, '/', '', true, true);
        } else {
            setcookie('pdf_sig', '0', time() - 3600, '/', '', true, true);
        }

        $sql = 'UPDATE users SET
            limit_nb = :new_limit,
            display_size = :new_display_size,
            orderby = :new_orderby,
            sort = :new_sort,
            sc_create = :new_sc_create,
            sc_edit = :new_sc_edit,
            sc_submit = :new_sc_submit,
            sc_todo = :new_sc_todo,
            show_team = :new_show_team,
            show_team_template = :new_show_team_template,
            chem_editor = :new_chem_editor,
            json_editor = :new_json_editor,
            lang = :new_lang,
            default_read = :new_default_read,
            default_write = :new_default_write,
            single_column_layout = :new_layout,
            cjk_fonts = :new_cjk_fonts,
            pdfa = :new_pdfa,
            pdf_format = :new_pdf_format,
            use_markdown = :new_use_markdown,
            inc_files_pdf = :new_inc_files_pdf
            WHERE userid = :userid;';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':new_limit', $new_limit);
        $req->bindParam(':new_display_size', $new_display_size);
        $req->bindParam(':new_orderby', $new_orderby);
        $req->bindParam(':new_sort', $new_sort);
        $req->bindParam(':new_sc_create', $new_sc_create);
        $req->bindParam(':new_sc_edit', $new_sc_edit);
        $req->bindParam(':new_sc_submit', $new_sc_submit);
        $req->bindParam(':new_sc_todo', $new_sc_todo);
        $req->bindParam(':new_show_team', $new_show_team);
        $req->bindParam(':new_show_team_template', $new_show_team_template);
        $req->bindParam(':new_chem_editor', $new_chem_editor);
        $req->bindParam(':new_json_editor', $new_json_editor);
        $req->bindParam(':new_lang', $new_lang);
        $req->bindParam(':new_default_read', $new_default_read);
        $req->bindParam(':new_default_write', $new_default_write);
        $req->bindParam(':new_layout', $new_layout);
        $req->bindParam(':new_cjk_fonts', $new_cjk_fonts);
        $req->bindParam(':new_pdfa', $new_pdfa);
        $req->bindParam(':new_pdf_format', $new_pdf_format);
        $req->bindParam(':new_use_markdown', $new_use_markdown);
        $req->bindParam(':new_inc_files_pdf', $new_inc_files_pdf);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Update things from UCP
     *
     * @param array $params
     * @return void
     */
    public function updateAccount(array $params): void
    {
        $params['firstname'] = filter_var($params['firstname'], FILTER_SANITIZE_STRING);
        $params['lastname'] = filter_var($params['lastname'], FILTER_SANITIZE_STRING);
        $params['email'] = filter_var($params['email'], FILTER_SANITIZE_EMAIL);

        if ($this->isDuplicateEmail($params['email']) && ($params['email'] != $this->userData['email'])) {
            throw new ImproperActionException(_('Someone is already using that email address!'));
        }

        // Check phone
        $params['phone'] = filter_var($params['phone'], FILTER_SANITIZE_STRING);
        // Check cellphone
        $params['cellphone'] = filter_var($params['cellphone'], FILTER_SANITIZE_STRING);
        // Check skype
        $params['skype'] = filter_var($params['skype'], FILTER_SANITIZE_STRING);

        // Check website
        $params['website'] = filter_var($params['website'], FILTER_VALIDATE_URL);

        $sql = 'UPDATE users SET
            email = :email,
            firstname = :firstname,
            lastname = :lastname,
            phone = :phone,
            cellphone = :cellphone,
            skype = :skype,
            website = :website
            WHERE userid = :userid';
        $req = $this->Db->prepare($sql);

        $req->bindParam(':email', $params['email']);
        $req->bindParam(':firstname', $params['firstname']);
        $req->bindParam(':lastname', $params['lastname']);
        $req->bindParam(':phone', $params['phone']);
        $req->bindParam(':cellphone', $params['cellphone']);
        $req->bindParam(':skype', $params['skype']);
        $req->bindParam(':website', $params['website']);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Update the password for the user
     *
     * @param string $password The new password
     * @return void
     */
    public function updatePassword(string $password): void
    {
        Check::passwordLength($password);

        $salt = \hash('sha512', \bin2hex(\random_bytes(16)));
        $passwordHash = \hash('sha512', $salt . $password);

        $sql = 'UPDATE users SET salt = :salt, password = :password, token = null WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':salt', $salt);
        $req->bindParam(':password', $passwordHash);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Validate current user instance
     *
     * @return void
     */
    public function validate(): void
    {
        $sql = 'UPDATE users SET validated = 1 WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
        // send an email to the user
        $Email = new Email(new Config(), $this);
        $Email->alertUserIsValidated($this->userData['email']);
    }

    /**
     * Archive/Unarchive a user
     *
     * @return void
     */
    public function toggleArchive(): void
    {
        $sql = 'UPDATE users SET archived = IF(archived = 1, 0, 1), token = null WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Lock all the experiments owned by user
     *
     * @return void
     */
    public function lockExperiments(): void
    {
        $sql = 'UPDATE experiments
            SET locked = :locked, lockedby = :userid, lockedwhen = CURRENT_TIMESTAMP WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindValue(':locked', 1);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
    }

    /**
     * Destroy user. Will completely remove everything from the user.
     *
     * @return void
     */
    public function destroy(): void
    {
        $UsersHelper = new UsersHelper();
        if ($UsersHelper->hasExperiments((int) $this->userData['userid'])) {
            throw new ImproperActionException('Cannot delete a user that owns experiments!');
        }
        $sql = 'DELETE FROM users WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);

        // remove all experiments from this user
        $sql = 'SELECT id FROM experiments WHERE userid = :userid';
        $req = $this->Db->prepare($sql);
        $req->bindParam(':userid', $this->userData['userid'], PDO::PARAM_INT);
        $this->Db->execute($req);
        while ($exp = $req->fetch()) {
            $Experiments = new Experiments($this, (int) $exp['id']);
            $Experiments->destroy();
        }
    }
}
