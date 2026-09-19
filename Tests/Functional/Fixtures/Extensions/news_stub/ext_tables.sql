CREATE TABLE tx_news_domain_model_news (
    title varchar(255) DEFAULT '' NOT NULL,
    content_elements int(11) DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    tx_news_related_news int(11) DEFAULT '0' NOT NULL
);
