<?php

declare(strict_types=1);

namespace Dolibarr\Tests\Integration\Orm\Fixture;

use Dolibarr\Core\Database\Connection;

/**
 * Scratch tables of the repository tests, created and dropped by each test with the DDL of the connection's
 * dialect: articles, their extra fields (shaped as a Dolibarr <table>_extrafields), authors, tags and the
 * article/tag pivot.
 */
final class OrmTestTables
{
	private const TABLES = ['mvc_orm_article_tag', 'mvc_orm_tag', 'mvc_orm_author', 'mvc_orm_article_extrafields', 'mvc_orm_article'];

	public static function create(Connection $connection): void
	{
		self::drop($connection);
		if ($connection->dialect() === 'pgsql') {
			$statements = [
				'CREATE TABLE @mvc_orm_article (rowid SERIAL PRIMARY KEY, entity integer NOT NULL DEFAULT 1, title varchar(128) NOT NULL,'
					.' status smallint NOT NULL DEFAULT 0, price numeric NULL, published_on date NULL, datec timestamp NULL, fk_author integer NULL,'
					.' tms timestamp DEFAULT CURRENT_TIMESTAMP)',
				'CREATE TABLE @mvc_orm_article_extrafields (rowid SERIAL PRIMARY KEY, tms timestamp DEFAULT CURRENT_TIMESTAMP,'
					.' fk_object integer NOT NULL UNIQUE, import_key varchar(14) NULL, color varchar(32) NULL, weight integer NULL)',
				'CREATE TABLE @mvc_orm_author (rowid SERIAL PRIMARY KEY, name varchar(64) NOT NULL)',
				'CREATE TABLE @mvc_orm_tag (rowid SERIAL PRIMARY KEY, label varchar(64) NOT NULL)',
				'CREATE TABLE @mvc_orm_article_tag (fk_article integer NOT NULL, fk_tag integer NOT NULL, PRIMARY KEY (fk_article, fk_tag))',
			];
		} else {
			$statements = [
				'CREATE TABLE @mvc_orm_article (rowid integer AUTO_INCREMENT PRIMARY KEY, entity integer NOT NULL DEFAULT 1, title varchar(128) NOT NULL,'
					.' status smallint NOT NULL DEFAULT 0, price double NULL, published_on date NULL, datec datetime NULL, fk_author integer NULL,'
					.' tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB',
				'CREATE TABLE @mvc_orm_article_extrafields (rowid integer AUTO_INCREMENT PRIMARY KEY, tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
					.' fk_object integer NOT NULL, import_key varchar(14) NULL, color varchar(32) NULL, weight integer NULL, UNIQUE KEY uk_mvc_orm_article_extrafields (fk_object)) ENGINE=InnoDB',
				'CREATE TABLE @mvc_orm_author (rowid integer AUTO_INCREMENT PRIMARY KEY, name varchar(64) NOT NULL) ENGINE=InnoDB',
				'CREATE TABLE @mvc_orm_tag (rowid integer AUTO_INCREMENT PRIMARY KEY, label varchar(64) NOT NULL) ENGINE=InnoDB',
				'CREATE TABLE @mvc_orm_article_tag (fk_article integer NOT NULL, fk_tag integer NOT NULL, PRIMARY KEY (fk_article, fk_tag)) ENGINE=InnoDB',
			];
		}
		foreach ($statements as $statement) {
			$connection->execute($statement);
		}
	}

	public static function drop(Connection $connection): void
	{
		foreach (self::TABLES as $table) {
			$connection->execute('DROP TABLE IF EXISTS @'.$table);
		}
	}
}
