<?php

declare(strict_types = 1);

namespace Drupal\Tests\ldap_servers\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\ldap_servers\Entity\Server;
use Drupal\ldap_servers\FakeBridge;
use Symfony\Component\Ldap\Entry;

/**
 * Group manager test.
 *
 * @group ldap
 */
class GroupManagerTest extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ldap_servers', 'externalauth'];

  /**
   * Server.
   *
   * @var \Drupal\ldap_servers\Entity\Server
   */
  protected $server;

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('ldap_server');
    $this->server = Server::create(['id' => 'example']);
    $this->server->set('grp_user_memb_attr', 'memberOf');
    $this->server->set('grp_user_memb_attr_exists', TRUE);

    $bridge = new FakeBridge(
      $this->container->get('logger.channel.ldap_servers'),
      $this->container->get('entity_type.manager')
    );
    $bridge->setBindResult(TRUE);
    $this->container->set('ldap.bridge', $bridge);
  }

  /**
   * Test group users membership from user attribute.
   */
  public function testGroupUserMembershipsFromUserAttr(): void {
    /** @var \Drupal\ldap_servers\LdapGroupManager $group_manager */
    $group_manager = $this->container->get('ldap.group_manager');
    $memberships = [
      'cn=group1,ou=people,dc=hogwarts,dc=edu',
      'cn=group2,ou=people,dc=hogwarts,dc=edu',
    ];
    $entry = new Entry('cn=hpotter,ou=people,dc=hogwarts,dc=edu', [
      'cn' => [0 => 'hpotter'],
      'mail' => [
        0 => 'hpotter@hogwarts.edu',
        1 => 'hpotter@students.hogwarts.edu',
      ],
      'memberOf' => $memberships,
    ]
    );
    $group_manager->setServer($this->server);
    $result = $group_manager->groupUserMembershipsFromUserAttr($entry);
    self::assertEquals($memberships, $result);
  }

}
