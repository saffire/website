CREATE TABLE paste (
  paste_id varchar(200) NOT NULL,
  name varchar(100) DEFAULT NULL,
  paste text NOT NULL,
  output text NOT NULL,
  private tinyint(1) NOT NULL DEFAULT '0',
  added datetime NOT NULL,
  image VARCHAR(100),
  PRIMARY KEY ( paste_id )
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
