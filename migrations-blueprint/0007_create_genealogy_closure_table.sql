CREATE TABLE genealogy_closure (
    ancestor_id   BIGINT UNSIGNED NOT NULL,
    descendant_id BIGINT UNSIGNED NOT NULL,
    depth         INT UNSIGNED NOT NULL,
    PRIMARY KEY (ancestor_id, descendant_id),
    KEY idx_closure_descendant   (descendant_id),
    KEY idx_closure_anc_depth    (ancestor_id, depth),
    CONSTRAINT fk_closure_ancestor   FOREIGN KEY (ancestor_id)   REFERENCES distributors(id) ON DELETE CASCADE,
    CONSTRAINT fk_closure_descendant FOREIGN KEY (descendant_id) REFERENCES distributors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
