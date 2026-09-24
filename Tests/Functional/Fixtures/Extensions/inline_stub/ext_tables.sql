CREATE TABLE tx_easyws_item (
    header varchar(255) DEFAULT '' NOT NULL,
    foreign_table_parent_uid int(11) DEFAULT '0' NOT NULL,
    fieldname varchar(64) DEFAULT '' NOT NULL
);

CREATE TABLE tx_easyws_other (
    header varchar(255) DEFAULT '' NOT NULL,
    foreign_table_parent_uid int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    easyws_items int(11) DEFAULT '0' NOT NULL,
    easyws_others int(11) DEFAULT '0' NOT NULL,
    easyws_extra_1 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_2 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_3 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_4 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_5 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_6 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_7 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_8 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_9 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_10 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_11 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_12 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_13 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_14 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_15 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_16 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_17 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_18 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_19 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_20 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_21 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_22 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_23 int(11) DEFAULT '0' NOT NULL,
    easyws_extra_24 int(11) DEFAULT '0' NOT NULL
);
