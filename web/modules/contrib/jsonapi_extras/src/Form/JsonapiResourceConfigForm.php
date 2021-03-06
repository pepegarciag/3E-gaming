<?php

namespace Drupal\jsonapi_extras\Form;

use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Drupal\jsonapi_extras\Entity\JsonapiResourceConfig;
use Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base form for jsonapi_resource_config.
 */
class JsonapiResourceConfigForm extends EntityForm {

  /**
   * The bundle information service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The JSON API resource type repository.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepository
   */
  protected $resourceTypeRepository;

  /**
   * The field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManager
   */
  protected $fieldManager;

  /**
   * The entity type repository.
   *
   * @var \Drupal\Core\Entity\EntityTypeRepositoryInterface
   */
  protected $entityTypeRepository;

  /**
   * The field enhancer manager.
   *
   * @var \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager
   */
  protected $enhancerManager;

  /**
   * The JSON API extras config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The current route match.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * JsonapiResourceConfigForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   Bundle information service.
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepository $resource_type_repository
   *   The JSON API resource type repository.
   * @param \Drupal\Core\Entity\EntityFieldManager $field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerManager $enhancer_manager
   *   The plugin manager for the resource field enhancer.
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   The config instance.
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   */
  public function __construct(EntityTypeBundleInfoInterface $bundle_info, ResourceTypeRepository $resource_type_repository, EntityFieldManager $field_manager, EntityTypeRepositoryInterface $entity_type_repository, ResourceFieldEnhancerManager $enhancer_manager, ImmutableConfig $config, Request $request, TypedConfigManagerInterface $typed_config_manager) {
    $this->bundleInfo = $bundle_info;
    $this->resourceTypeRepository = $resource_type_repository;
    $this->fieldManager = $field_manager;
    $this->entityTypeRepository = $entity_type_repository;
    $this->enhancerManager = $enhancer_manager;
    $this->config = $config;
    $this->request = $request;
    $this->typedConfigManager = $typed_config_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('jsonapi.resource_type.repository'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.repository'),
      $container->get('plugin.manager.resource_field_enhancer'),
      $container->get('config.factory')->get('jsonapi_extras.settings'),
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('config.typed')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    // Disable caching on this form.
    $form_state->setCached(FALSE);

    $entity_type_id = $this->request->get('entity_type_id');
    $bundle = $this->request->get('bundle');

    /** @var JsonapiResourceConfig $entity */
    $entity = $this->getEntity();
    $resource_id = $entity->get('id');
    // If we are editing an entity we don't want the Entity Type and Bundle
    // picker, that info is locked.
    if (!$entity_type_id || !$bundle) {
      if ($resource_id) {
        list($entity_type_id, $bundle) = explode('--', $resource_id);
        $form['#title'] = $this->t('Edit %label resource config', ['%label' => $resource_id]);
      }
      else {
        list($entity_type_id, $bundle) = $this->buildEntityTypeBundlePicker($form, $form_state);
        if (!$entity_type_id) {
          return $form;
        }
      }
    }

    if ($entity_type_id && $resource_type = $this->resourceTypeRepository->get($entity_type_id, $bundle)) {
      // Get the JSON API resource type.
      $resource_config_id = sprintf('%s--%s', $entity_type_id, $bundle);
      $existing_entity = $this->entityTypeManager
        ->getStorage('jsonapi_resource_config')->load($resource_config_id);
      if ($existing_entity && $entity->isNew()) {
        drupal_set_message($this->t('This override already exists, please edit it instead.'));
        return $form;
      }
      $form['bundle_wrapper']['fields_wrapper'] = $this->buildOverridesForm($resource_type, $entity);
      $form['id'] = [
        '#type' => 'hidden',
        '#value' => sprintf('%s--%s', $entity_type_id, $bundle),
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if (!method_exists($this->typedConfigManager, 'createFromNameAndData')) {
      // Versions of Drupal before 8.4 have poor support for constraints. In
      // those scenarios we don't validate the form submission.
      return;
    }
    $typed_config = $this->typedConfigManager
      ->createFromNameAndData($this->entity->id(), $this->entity->toArray());
    $constraints = $typed_config->validate();
    /** @var \Symfony\Component\Validator\ConstraintViolation $violation */
    foreach ($constraints as $violation) {
      $form_path = str_replace('.', '][', $violation->getPropertyPath());
      $form_state->setErrorByName($form_path, $violation->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $resource_config = $this->entity;
    $status = $resource_config->save();

    switch ($status) {
      case SAVED_NEW:
        drupal_set_message($this->t('Created the %label JSON API Resource overwrites.', [
          '%label' => $resource_config->label(),
        ]));
        break;

      default:
        drupal_set_message($this->t('Saved the %label JSON API Resource overwrites.', [
          '%label' => $resource_config->label(),
        ]));
    }
    $form_state->setRedirectUrl($resource_config->urlInfo('collection'));
  }

  /**
   * Implements callback for Ajax event on entity type or bundle selection.
   *
   * @param array $form
   *   From render array.
   *
   * @return array
   *   Color selection section of the form.
   */
  public function bundleCallback(array &$form) {
    return $form['bundle_wrapper'];
  }

  /**
   * Builds the part of the form that contains the overrides.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type being overridden.
   * @param \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig $entity
   *   The configuration entity backing this form.
   *
   * @return array
   *   The partial form.
   */
  protected function buildOverridesForm(ResourceType $resource_type, JsonapiResourceConfig $entity) {
    $entity_type_id = $resource_type->getEntityTypeId();
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $bundle = $resource_type->getBundle();
    if ($entity_type instanceof ContentEntityTypeInterface) {
      $field_names = array_map(function (FieldDefinitionInterface $field_definition) {
        return $field_definition->getName();
      }, $this->fieldManager->getFieldDefinitions($entity_type_id, $bundle));
    }
    else {
      $field_names = array_keys($entity_type->getKeys());
    }

    $overrides_form['overrides']['entity'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity'),
      '#description' => $this->t('Override configuration for the resource entity.'),
      '#open' => !$entity->get('resourceType') || !$entity->get('path'),
    ];

    $overrides_form['overrides']['entity']['disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disabled'),
      '#description' => $this->t('Check this if you want to disable this resource. Disabling a resource can have unexpected results when following relationships belonging to that resource.'),
      '#default_value' => $entity->get('disabled'),
    ];

    $resource_type_name = $entity->get('resourceType');
    if (!$resource_type_name) {
      $resource_type_name = sprintf('%s--%s', $entity_type_id, $bundle);
    }
    $overrides_form['overrides']['entity']['resourceType'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource Type'),
      '#description' => $this->t('Overrides the type of the resource. Example: Change "node--article" to "articles".'),
      '#default_value' => $resource_type_name,
      '#states' => [
        'visible' => [
          ':input[name="disabled"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $path = $entity->get('path');
    if (!$path) {
      $path = sprintf('%s/%s', $entity_type_id, $bundle);
    }

    $prefix = $this->config->get('path_prefix');
    $overrides_form['overrides']['entity']['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource Path'),
      '#field_prefix' => sprintf('/%s/', $prefix),
      '#description' => $this->t('Overrides the path of the resource. Example: Use "articles" to change "/@prefix/node/article" to "/@prefix/articles".', [
        '@prefix' => $prefix,
      ]),
      '#default_value' => $path,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="disabled"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $overrides_form['overrides']['fields'] = [
      '#type' => 'details',
      '#title' => $this->t('Fields'),
      '#open' => TRUE,
    ];

    $markup = '';
    $markup .= '<dl>';
    $markup .= '<dt>' . t('Disabled') . '</dt>';
    $markup .= '<dd>' . t('Check this if you want to disable this field completely. Disabling required fields will cause problems when writing to the resource.') . '</dd>';
    $markup .= '<dt>' . t('Alias') . '</dt>';
    $markup .= '<dd>' . t('Overrides the field name with a custom name. Example: Change "field_tags" to "tags".') . '</dd>';
    $markup .= '<dt>' . t('Enhancer') . '</dt>';
    $markup .= '<dd>' . t('Select an enhancer to manipulate the public output coming in and out.') . '</dd>';
    $markup .= '</dl>';
    $overrides_form['overrides']['fields']['info'] = [
      '#markup' => $markup,
    ];

    $overrides_form['overrides']['fields']['resourceFields'] = [
      '#type' => 'table',
      '#header' => [
        'disabled' => $this->t('Disabled'),
        'fieldName' => $this->t('Field name'),
        'publicName' => $this->t('Alias'),
        'enhancer' => $this->t('Enhancer'),
      ],
      '#empty' => $this->t('No fields available.'),
      '#states' => [
        'visible' => [
          ':input[name="disabled"]' => ['checked' => FALSE],
        ],
      ],
    ];

    foreach ($field_names as $field_name) {
      $overrides_form['overrides']['fields']['resourceFields'][$field_name] = $this->buildOverridesField($field_name, $entity);
    }

    return $overrides_form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, FormStateInterface $form_state) {
    /** @var JsonapiResourceConfig $entity */
    $entity = parent::buildEntity($form, $form_state);

    // Trim slashes from path.
    $path = trim($form_state->getValue('path'), '/');
    if (strlen($path) > 0) {
      $entity->set('path', $path);
    }

    return $entity;
  }

  /**
   * Builds the part of the form that overrides the field.
   *
   * @param string $field_name
   *   The field name of the field being overridden.
   * @param \Drupal\jsonapi_extras\Entity\JsonapiResourceConfig $entity
   *   The config entity backed by this form.
   *
   * @return array
   *   The partial form.
   */
  protected function buildOverridesField($field_name, JsonapiResourceConfig $entity) {
    $resource_fields = array_filter($entity->get('resourceFields'), function (array $resource_field) use ($field_name) {
      return $resource_field['fieldName'] == $field_name;
    });
    $resource_field = array_shift($resource_fields);
    $overrides_form = [];
    $overrides_form['disabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disabled'),
      '#title_display' => 'invisible',
      '#default_value' => $resource_field['disabled'],
    ];
    $overrides_form['fieldName'] = [
      '#type' => 'hidden',
      '#value' => $field_name,
      '#prefix' => $field_name,
    ];
    $overrides_form['publicName'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Override Public Name'),
      '#title_display' => 'hidden',
      '#default_value' => empty($resource_field['publicName']) ? $field_name : $resource_field['publicName'],
      '#states' => [
        'visible' => [
          ':input[name="resourceFields[' . $field_name . '][disabled]"]' => [
            'checked' => FALSE,
          ],
        ],
      ],
    ];
    // Build the select field for the list of enhancers.
    $overrides_form['enhancer'] = [
      '#type' => 'fieldgroup',
      '#states' => [
        'visible' => [
          ':input[name="resourceFields[' . $field_name . '][disabled]"]' => [
            'checked' => FALSE,
          ],
        ],
      ],
    ];
    $options = array_reduce(
      $this->enhancerManager->getDefinitions(),
      function (array $carry, array $definition) {
        $carry[$definition['id']] = $definition['label'];
        return $carry;
      },
      ['' => $this->t('- Select -')]
    );
    $id = empty($resource_field['enhancer']['id'])
      ? ''
      : $resource_field['enhancer']['id'];
    $overrides_form['enhancer']['id'] = [
      '#type' => 'select',
      '#options' => $options,
      '#ajax' => [
        'callback' => '::getEnhancerSettings',
        'wrapper' => $field_name . '-settings-wrapper',
      ],
      '#default_value' => $id,
    ];
    $overrides_form['enhancer']['settings'] = [
      '#type' => 'container',
      '#attributes' => ['id' => $field_name . '-settings-wrapper'],
    ];
    if (!empty($resource_field['enhancer']['id'])) {
      /** @var \Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerInterface $enhancer */
      $enhancer = $this->enhancerManager
        ->createInstance($resource_field['enhancer']['id'], []);
      $overrides_form['enhancer']['settings'] += $enhancer
        ->getSettingsForm($resource_field);
    }
    return $overrides_form;
  }

  /**
   * Build the entity picker widget and return the entity type and bundle IDs.
   *
   * @param array $form
   *   The form passed by reference to update it.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The state of the form.
   *
   * @return array
   *   The entity types ID and the bundle ID.
   */
  protected function buildEntityTypeBundlePicker(array &$form, FormStateInterface $form_state) {
    $form['_entity_type_id'] = [
      '#title' => $this->t('Entity Type'),
      '#type' => 'select',
      '#options' => $this->entityTypeRepository->getEntityTypeLabels(TRUE),
      '#empty_option' => $this->t('- Select -'),
      '#required' => TRUE,
      '#ajax' => [
        'callback' => '::bundleCallback',
        'wrapper' => 'bundle-wrapper',
      ],
    ];

    if (isset($parameter['entity_type_id'])) {
      $form['_entity_type_id'] = [
        '#type' => 'hidden',
        '#value' => $parameter['entity_type_id'],
      ];
    }

    $form['bundle_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'bundle-wrapper'],
    ];
    if (!$entity_type_id = $form_state->getValue('_entity_type_id')) {
      return [$entity_type_id, NULL];
    }
    $has_bundles = (bool) $this->entityTypeManager
      ->getDefinition($entity_type_id)->getBundleEntityType();
    if ($has_bundles) {
      $bundles = [];
      $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);
      foreach ($bundle_info as $bundle_id => $info) {
        $bundles[$bundle_id] = $info['translatable'] ? $this->t($info['label']) : $info['label'];
      }
      $form['bundle_wrapper']['_bundle_id'] = [
        '#type' => 'select',
        '#empty_option' => $this->t('- Select -'),
        '#title' => $this->t('Bundle'),
        '#options' => $bundles,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::bundleCallback',
          'wrapper' => 'bundle-wrapper',
        ],
      ];
    }
    else {
      $form['bundle_wrapper']['_bundle_id'] = [
        '#type' => 'hidden',
        '#value' => $entity_type_id,
      ];
    }
    $bundle = $has_bundles
      ? $form_state->getValue('_bundle_id')
      : $entity_type_id;
    return [$entity_type_id, $bundle];
  }

  /**
   * AJAX callback to get the form settings for the enhancer for a field.
   *
   * @param array $form
   *   The reference to the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return mixed
   *   The specific form sub-tree in the form.
   */
  public static function getEnhancerSettings(array &$form, FormStateInterface $form_state) {
    // Find what is the field name that triggered the AJAX request.
    $user_input = $form_state->getUserInput();
    $parts = explode('[', $user_input['_triggering_element_name']);
    $field_name = rtrim($parts[1], ']');
    // Now return the sub-tree for the settings on the enhancer plugin.
    return $form['bundle_wrapper']['fields_wrapper']['overrides']['fields']['resourceFields'][$field_name]['enhancer']['settings'];
  }

}
