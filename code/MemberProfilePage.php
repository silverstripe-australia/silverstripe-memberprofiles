<?php
/**
 * A MemberProfilePage allows the administratior to set up a page with a subset of the
 * fields available on the member object, then allow members to register and edit
 * their profile using these fields.
 *
 * Members who have permission to create new members can also be allowed to
 * create new members via this page.
 *
 * It also supports email validation.
 *
 * @package silverstripe-memberprofiles
 */

namespace Silverstripe\MemberProfiles;
use Page;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Member;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\TreeMultiselectField;
use Silverstripe\MemberProfiles\MemberProfilesAddSectionAction;
use SilverStripe\Forms\GridField\GridFieldDataColumns;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\HTMLEditor\HTMLEditorField;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\Forms\TextareaField;
use Silverstripe\MemberProfiles\MemberConfirmationEmail;
use SilverStripe\Forms\CheckboxField;
use Silverstripe\MemberProfiles\MemberProfileField;
use Silverstripe\MemberProfiles\MemberProfileFieldsSection;
use SilverStripe\Security\Group;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\TreeDropdownField;

class MemberProfilePage extends Page implements PermissionProvider {

    private static $table_name = 'MemberProfilePage';

	private static $db = array (
		'ProfileTitle'             => 'Varchar(255)',
		'RegistrationTitle'        => 'Varchar(255)',
		'AfterRegistrationTitle'   => 'Varchar(255)',
		'ProfileContent'           => 'HTMLText',
		'RegistrationContent'      => 'HTMLText',
		'AfterRegistrationContent' => 'HTMLText',
		'AllowRegistration'        => 'Boolean',
		'AllowProfileViewing'      => 'Boolean',
		'AllowProfileEditing'      => 'Boolean',
		'AllowAdding'              => 'Boolean',
		'RegistrationRedirect'     => 'Boolean',
		'RequireApproval'          => 'Boolean',
		'EmailType'                => 'Enum("Validation, Confirmation, None", "None")',
		'EmailFrom'                => 'Varchar(255)',
		'EmailSubject'             => 'Varchar(255)',
		'EmailTemplate'            => 'Text',
		'ConfirmationTitle'        => 'Varchar(255)',
		'ConfirmationContent'      => 'HTMLText'
	);

	private static $has_one = array(
		'PostRegistrationTarget' => SiteTree::class,
	);

	private static $has_many = array (
		'Fields'   => MemberProfileField::class,
		'Sections' => MemberProfileSection::class
	);

	private static $many_many = array (
		'Groups'           => Group::class,
		'SelectableGroups' => Group::class,
		'ApprovalGroups'   => Group::class
	);

	private static $defaults = array (
		'ProfileTitle'             => 'Edit Profile',
		'RegistrationTitle'        => 'Register / Log In',
		'AfterRegistrationTitle'   => 'Registration Successful',
		'AfterRegistrationContent' => '<p>Thank you for registering!</p>',
		'AllowRegistration'        => true,
		'AllowProfileViewing'      => true,
		'AllowProfileEditing'      => true,
		'ConfirmationTitle'        => 'Account Confirmed',
		'ConfirmationContent'      => '<p>Your account is now active, and you have been logged in. Thankyou!</p>'
	);

	/**
	 * An array of default settings for some standard member fields.
	 *
	 * @var array
	 */
	public static $profile_field_defaults = array(
		'Email' => array(
			'RegistrationVisibility' => 'Edit',
			'ProfileVisibility'      => 'Edit',
			'PublicVisibility'       => 'MemberChoice'
		),
		'FirstName' => array(
			'RegistrationVisibility' => 'Edit',
			'ProfileVisibility'      => 'Edit',
			'MemberListVisible'      => true,
			'PublicVisibility'       => 'Display'
		),
		'Surname' => array(
			'RegistrationVisibility'  => 'Edit',
			'ProfileVisibility'       => 'Edit',
			'MemberListVisible'       => true,
			'PublicVisibility'        => 'MemberChoice',
			'PublicVisibilityDefault' => true
		),
		'Password' => array(
			'RegistrationVisibility' => 'Edit',
			'ProfileVisibility'      => 'Edit'
		)
	);

	private static $description = '';

	private static $icon = 'memberprofiles/images/memberprofilepage.png';

	/**
	 * If profile editing is disabled, but the current user can add members,
	 * just link directly to the add action.
	 *
	 * @param string $action
	 */
	public function Link($action = null) {
		if(
			!$action
			&& Member::currentUserID()
			&& !$this->AllowProfileEditing
			&& $this->CanAddMembers()
		) {
			$action = 'add';
		}

		return parent::Link($action);
	}

	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$fields->addFieldToTab('Root', new TabSet('Profile', _t('MemberProfiles.PROFILE', 'Profile')));
		$fields->addFieldToTab('Root', new Tab('ContentBlocks', _t('MemberProfiles.CONTENTBLOCKS', 'Content Blocks')));
		$fields->addFieldToTab('Root', new Tab('Email', _t('MemberProfiles.Email', 'Email')));
		$fields->fieldByName('Root.Main')->setTitle(_t('MemberProfiles.MAIN', 'Main'));

		$fields->addFieldsToTab('Root.Profile', array(
			new Tab(
				'Fields',
				_t('MemberProfiles.FIELDS', 'Fields'),
				new GridField(
					'Fields',
					_t('MemberProfiles.PROFILEFIELDS', 'Profile Fields'),
					$this->Fields(),
					$grid = GridFieldConfig_RecordEditor::create()
						->removeComponentsByType('GridFieldDeleteAction')
						->removeComponentsByType('GridFieldAddNewButton')
				)
			),
			new Tab(
				'Groups',
				_t('MemberProfiles.GROUPS', 'Groups'),
				$groups = new TreeMultiselectField(
					'Groups', _t('MemberProfiles.GROUPS', 'Groups'),
					Group::class
				),
				$selectable = new TreeMultiselectField(
					'SelectableGroups',
					_t('MemberProfiles.SELECTABLEGROUPS', 'Selectable Groups'),
					Group::class
				)
			),
			new Tab(
				'PublicProfile',
				_t('MemberProfiles.PUBLICPROFILE', 'Public Profile'),
				new GridField(
					'Sections',
					_t('MemberProfiles.PROFILESECTIONS', 'Profile Sections'),
					$this->Sections(),
					GridFieldConfig_RecordEditor::create()
						->removeComponentsByType('GridFieldAddNewButton')
						->addComponent(new MemberProfilesAddSectionAction())
				)
			)
		));

        // TODO investigate as it breaks with error "Call to a member function setFieldFormatting() on null "
//		$grid->getComponentByType('GridFieldDataColumns')->setFieldFormatting(array(
//			'Unique'   => function($val, $obj) { return $obj->dbObject('Unique')->Nice(); },
//			'Required' => function($val, $obj) { return $obj->dbObject('Required')->Nice(); }
//		));

		if(class_exists(GridFieldOrderableRows::class)) {
			$grid->addComponent(new GridFieldOrderableRows('Sort'));
		} elseif(class_exists('GridFieldSortableRows')) {
			$grid->addComponent(new GridFieldSortableRows('Sort'));
		}

		if(!$this->AllowProfileViewing) {
			$disabledNote = new LiteralField('PublisProfileDisabledNote', sprintf(
				'<p class="message notice">%s</p>', _t(
					'MemberProfiles.PUBLICPROFILEDISABLED',
					'Public profiles are currently disabled, you can enable them ' .
					'in the "Settings" tab.'
				)
			));
			$fields->insertBefore($disabledNote, 'Sections');
		}

		$groups->setDescription(_t(
			'MemberProfiles.GROUPSNOTE',
			'Any users registering via this page will always be added to ' .
			'these groups (if registration is enabled). Conversely, a member ' .
			'must belong to these groups in order to edit their profile on ' .
			'this page.'
		));

		$selectable->setDescription(_t(
			'MemberProfiles.SELECTABLENOTE',
			'Users can choose to belong to these groups, if the  "Groups" field ' .
			'is enabled in the "Fields" tab.'
		));

		$fields->removeByName('Content', true);

		$contentFields = array();
		if($this->AllowRegistration) {
			$contentFields[] = 'Registration';
			$contentFields[] = 'AfterRegistration';
		}

		if($this->AllowProfileEditing) {
			$contentFields[] = 'Profile';
		}

		foreach($contentFields as $type) {
			$fields->addFieldToTab("Root.ContentBlocks", new ToggleCompositeField(
				"{$type}Toggle",
				 _t('MemberProfiles.'.  strtoupper($type), FormField::name_to_label($type)),
				array(
					new TextField("{$type}Title", _t('MemberProfiles.TITLE', 'Title')),
					$content = new HtmlEditorField("{$type}Content", _t('MemberProfiles.CONTENT', 'Content'))
				)
			));
			$content->setRows(15);
		}


		$fields->addFieldsToTab('Root.Email', array(
			new OptionsetField(
				'EmailType',
				_t('MemberProfiles.EMAILSETTINGS', 'Email Settings'),
				array(
					'Validation'   => _t('MemberProfiles.EMAILVALIDATION', 'Require email validation'),
					'Confirmation' => _t('MemberProfiles.EMAILCONFIRMATION', 'Send a confirmation email'),
					'None'         => _t('MemberProfiles.NONE', 'None')
				)
			),
			new ToggleCompositeField('EmailContentToggle',  _t('MemberProfiles.EMAILCONTENT', 'Email Content'), array(
				new TextField('EmailSubject', _t('MemberProfiles.EMAILSUBJECT', 'Email subject')),
				new TextField('EmailFrom', _t('MemberProfiles.EMAILFROM', 'Email from')),
				new TextareaField('EmailTemplate', _t('MemberProfiles.EMAILTEMPLATE', 'Email template')),
				new LiteralField('TemplateNote', sprintf(
					'<div class="field">%s</div>', MemberConfirmationEmail::TEMPLATE_NOTE
				))
			)),
			new ToggleCompositeField('ConfirmationContentToggle', _t('MemberProfiles.CONFIRMCONTENT', 'Confirmation Content'), array(
				new TextField('ConfirmationTitle', _t('MemberProfiles.TITLE', 'Title')),
				$confContent  = new HtmlEditorField('ConfirmationContent', _t('MemberProfiles.CONTENT', 'Content'))
			))
		));
		$confContent->setRows(15);

		return $fields;
	}

	public function getSettingsFields() {
		$fields = parent::getSettingsFields();

		$fields->addFieldToTab('Root', new Tab('Profile'), 'Settings');
		$fields->addFieldsToTab('Root.Profile', array(
			new CheckboxField(
				'AllowRegistration',
				_t('MemberProfiles.ALLOWREG', 'Allow registration via this page')
			),
			new CheckboxField(
				'AllowProfileEditing',
				_t('MemberProfiles.ALLOWEDITING', 'Allow users to edit their own profile on this page')
			),
			new CheckboxField(
				'AllowAdding',
				_t('MemberProfiles.ALLOWADD', 'Allow adding members via this page')
			),
			new CheckboxField(
				'AllowProfileViewing',
				_t('MemberProfiles.ALLOWPROFILEVIEWING', 'Enable public profiles?')
			),
			new CheckboxField(
				'RequireApproval',
				_t('MemberProfiles.REQUIREREGAPPROVAL', 'Require registration approval by an administrator?')
			),
			$approval = new TreeMultiselectField(
				'ApprovalGroups',
				_t('MemberProfiles.APPROVALGROUPS', 'Approval Groups'),
				Group::class
			),
			new CheckboxField(
				'RegistrationRedirect',
				_t('MemberProfiles.REDIRECTAFTERREG', 'Redirect after registration?')
			),
			new TreeDropdownField(
				'PostRegistrationTargetID',
				_t('MemberProfiles.REDIRECTTOPAGE', 'Redirect To Page'),
				SiteTree::class
			)
		));

		$approval->setDescription(_t(
			'MemberProfiles.NOTIFYTHESEGROUPS',
			'These groups will be notified to approve new registrations'
		));

		return $fields;
	}

	/**
	 * Get either the default or custom email template.
	 *
	 * @return string
	 */
	public function getEmailTemplate() {
		return ($t = $this->getField('EmailTemplate')) ? $t : MemberConfirmationEmail::DEFAULT_TEMPLATE;
	}

	/**
	 * Get either the default or custom email subject line.
	 *
	 * @return string
	 */
	public function getEmailSubject() {
		return ($s = $this->getField('EmailSubject')) ? $s : MemberConfirmationEmail::DEFAULT_SUBJECT;
	}

	/**
	 * Returns a list of profile field objects after synchronising them with the
	 * Member form fields.
	 *
	 * @return HasManyList
	 */
	public function Fields() {
		$list     = $this->getComponents('Fields');
		$fields   = singleton(Member::class)->getMemberFormFields()->dataFields();
		$included = array();

		foreach($list as $profileField) {
			if(!array_key_exists($profileField->MemberField, $fields)) {
				$profileField->delete();
			} else {
				$included[] = $profileField->MemberField;
			}
		}

		foreach($fields as $name => $field) {
			if(!in_array($name, $included)) {
				$profileField = new MemberProfileField();
				$profileField->MemberField = $name;

				if(isset(self::$profile_field_defaults[$name])) {
					$profileField->update(self::$profile_field_defaults[$name]);
				}

				$list->add($profileField);
			}
		}

		return $list;
	}

	public function onAfterWrite() {
		if ($this->isChanged('ID', 2)) {
			$section = new MemberProfileFieldsSection();
			$section->ParentID = $this->ID;
			$section->write();
		}

		parent::onAfterWrite();
	}

	/**
	 * @return bool
	 */
	public function CanAddMembers() {
		return $this->AllowAdding && singleton(Member::class)->canCreate();
	}
}

