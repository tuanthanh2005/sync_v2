<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attributeを承認してください。',
    'accepted_if' => ':otherが:valueの場合、:attributeを承認してください。',
    'active_url' => ':attributeには有効なURLを指定してください。',
    'after' => ':attributeには:dateより後の日付を指定してください。',
    'after_or_equal' => ':attributeには:date以降の日付を指定してください。',
    'alpha' => ':attributeにはアルファベットのみ使用できます。',
    'alpha_dash' => ':attributeには英数字、ハイフン(-)、アンダースコア(_)のみ使用できます。',
    'alpha_num' => ':attributeには英数字のみ使用できます。',
    'any_of' => ':attributeの値が無効です。',
    'array' => ':attributeには配列を指定してください。',
    'array_keys' => ':attributeには次のキーのみを含める必要があります: :values。',
    'ascii' => ':attributeには半角英数字と記号のみ使用できます。',
    'base64' => ':attributeには有効なBase64文字列を指定してください。',
    'before' => ':attributeには:dateより前の日付を指定してください。',
    'before_or_equal' => ':attributeには:date以前の日付を指定してください。',
    'between' => [
        'array' => ':attributeの項目数は:minから:maxの間にしてください。',
        'file' => ':attributeのファイルサイズは:minから:maxキロバイトの間にしてください。',
        'numeric' => ':attributeには:minから:maxまでの数値を指定してください。',
        'string' => ':attributeは:minから:max文字の間で入力してください。',
    ],
    'boolean' => ':attributeにはtrueまたはfalseを指定してください。',
    'can' => ':attributeに許可されていない値が含まれています。',
    'confirmed' => ':attributeが確認用の値と一致しません。',
    'contains' => ':attributeに必要な値が含まれていません。',
    'current_password' => 'パスワードが正しくありません。',
    'date' => ':attributeには有効な日付を指定してください。',
    'date_equals' => ':attributeには:dateと同じ日付を指定してください。',
    'date_format' => ':attributeの形式が「:format」と一致しません。',
    'decimal' => ':attributeは小数点以下:decimal桁で指定してください。',
    'declined' => ':attributeを拒否してください。',
    'declined_if' => ':otherが:valueの場合、:attributeを拒否してください。',
    'different' => ':attributeと:otherには異なる値を指定してください。',
    'digits' => ':attributeは:digits桁で入力してください。',
    'digits_between' => ':attributeは:minから:max桁の間で入力してください。',
    'dimensions' => ':attributeの画像サイズが無効です。',
    'distinct' => ':attributeの値が重複しています。',
    'doesnt_contain' => ':attributeには次の値を含めないでください: :values。',
    'doesnt_end_with' => ':attributeの末尾には次のいずれの値も指定できません: :values。',
    'doesnt_start_with' => ':attributeの先頭には次のいずれの値も指定できません: :values。',
    'email' => ':attributeには有効なメールアドレスを入力してください。',
    'encoding' => ':attributeは:encodingでエンコードされている必要があります。',
    'ends_with' => ':attributeは「:values」のいずれかで終わる必要があります。',
    'enum' => '選択された:attributeは無効です。',
    'exists' => '選択された:attributeは無効です。',
    'extensions' => ':attributeの拡張子は次のいずれかである必要があります: :values。',
    'file' => ':attributeにはファイルを指定してください。',
    'filled' => ':attributeには値を指定してください。',
    'gt' => [
        'array' => ':attributeの項目数は:value個より多くしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイトより大きくしてください。',
        'numeric' => ':attributeには:valueより大きい値を指定してください。',
        'string' => ':attributeは:value文字より長く入力してください。',
    ],
    'gte' => [
        'array' => ':attributeの項目数は:value個以上にしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイト以上にしてください。',
        'numeric' => ':attributeには:value以上の値を指定してください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'hex_color' => ':attributeには有効な16進カラーコードを指定してください。',
    'image' => ':attributeには画像ファイルを指定してください。',
    'in' => '選択された:attributeは無効です。',
    'in_array' => ':attributeは:otherの中に存在する必要があります。',
    'in_array_keys' => ':attributeには次のキーのうち少なくとも1つを含める必要があります: :values。',
    'integer' => ':attributeには整数を指定してください。',
    'ip' => ':attributeには有効なIPアドレスを指定してください。',
    'ipv4' => ':attributeには有効なIPv4アドレスを指定してください。',
    'ipv6' => ':attributeには有効なIPv6アドレスを指定してください。',
    'json' => ':attributeには有効なJSON文字列を指定してください。',
    'list' => ':attributeにはリストを指定してください。',
    'lowercase' => ':attributeは小文字で入力してください。',
    'lt' => [
        'array' => ':attributeの項目数は:value個未満にしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイト未満にしてください。',
        'numeric' => ':attributeには:value未満の値を指定してください。',
        'string' => ':attributeは:value文字未満で入力してください。',
    ],
    'lte' => [
        'array' => ':attributeの項目数は:value個以下にしてください。',
        'file' => ':attributeのファイルサイズは:valueキロバイト以下にしてください。',
        'numeric' => ':attributeには:value以下の値を指定してください。',
        'string' => ':attributeは:value文字以下で入力してください。',
    ],
    'mac_address' => ':attributeには有効なMACアドレスを指定してください。',
    'max' => [
        'array' => ':attributeの項目数は:max個以下にしてください。',
        'file' => ':attributeのファイルサイズは:maxキロバイト以下にしてください。',
        'numeric' => ':attributeには:max以下の数値を指定してください。',
        'string' => ':attributeは:max文字以下で入力してください。',
    ],
    'max_digits' => ':attributeは:max桁以下で入力してください。',
    'mimes' => ':attributeには「:values」タイプのファイルを指定してください。',
    'mimetypes' => ':attributeには「:values」タイプのファイルを指定してください。',
    'min' => [
        'array' => ':attributeの項目数は:min個以上にしてください。',
        'file' => ':attributeのファイルサイズは:minキロバイト以上にしてください。',
        'numeric' => ':attributeには:min以上の数値を指定してください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeは:min桁以上で入力してください。',
    'missing' => ':attributeが存在していてはいけません。',
    'missing_if' => ':otherが:valueの場合、:attributeが存在していてはいけません。',
    'missing_unless' => ':otherが:valueでない場合、:attributeが存在していてはいけません。',
    'missing_with' => ':valuesが存在する場合、:attributeが存在していてはいけません。',
    'missing_with_all' => ':valuesがすべて存在する場合、:attributeが存在していてはいけません。',
    'multiple_of' => ':attributeには:valueの倍数を指定してください。',
    'not_in' => '選択された:attributeは無効です。',
    'not_regex' => ':attributeの形式が正しくありません。',
    'numeric' => ':attributeには数値を指定してください。',
    'password' => [
        'letters' => ':attributeには少なくとも1つの文字を含める必要があります。',
        'mixed' => ':attributeには少なくとも1つの大文字と1つの小文字を含める必要があります。',
        'numbers' => ':attributeには少なくとも1つの数字を含める必要があります。',
        'symbols' => ':attributeには少なくとも1つの記号を含める必要があります。',
        'uncompromised' => '指定された:attributeはデータ漏洩で確認されています。別の:attributeを選択してください。',
    ],
    'present' => ':attributeが存在している必要があります。',
    'present_if' => ':otherが:valueの場合、:attributeが存在している必要があります。',
    'present_unless' => ':otherが:valueでない場合、:attributeが存在している必要があります。',
    'present_with' => ':valuesが存在する場合、:attributeが存在している必要があります。',
    'present_with_all' => ':valuesがすべて存在する場合、:attributeが存在している必要があります。',
    'prohibited' => ':attributeの入力は禁止されています。',
    'prohibited_if' => ':otherが:valueの場合、:attributeの入力は禁止されています。',
    'prohibited_if_accepted' => ':otherが承認された場合、:attributeの入力は禁止されています。',
    'prohibited_if_declined' => ':otherが拒否された場合、:attributeの入力は禁止されています。',
    'prohibited_unless' => ':otherが:valuesに含まれない場合、:attributeの入力は禁止されています。',
    'prohibits' => ':attributeが存在する場合、:otherは指定できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeは必須項目です。',
    'required_array_keys' => ':attributeには次のエントリを含める必要があります: :values。',
    'required_if' => ':otherが:valueの場合、:attributeは必須です。',
    'required_if_accepted' => ':otherが承認された場合、:attributeは必須です。',
    'required_if_declined' => ':otherが拒否された場合、:attributeは必須です。',
    'required_unless' => ':otherが:valuesに含まれない場合、:attributeは必須です。',
    'required_with' => ':valuesが指定されている場合、:attributeは必須です。',
    'required_with_all' => ':valuesがすべて指定されている場合、:attributeは必須です。',
    'required_without' => ':valuesが指定されていない場合、:attributeは必須です。',
    'required_without_all' => ':valuesがいずれも指定されていない場合、:attributeは必須です。',
    'same' => ':attributeと:otherには同じ値を指定してください。',
    'size' => [
        'array' => ':attributeは:size個の項目を含む必要があります。',
        'file' => ':attributeのファイルサイズは:sizeキロバイトにしてください。',
        'numeric' => ':attributeには:sizeを指定してください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'starts_with' => ':attributeは「:values」のいずれかで始まる必要があります。',
    'string' => ':attributeには文字列を指定してください。',
    'timezone' => ':attributeには有効なタイムゾーンを指定してください。',
    'unique' => ':attributeの値はすでに使用されています。',
    'uploaded' => ':attributeのアップロードに失敗しました。',
    'uppercase' => ':attributeは大文字で入力してください。',
    'url' => ':attributeには有効なURLを指定してください。',
    'ulid' => ':attributeには有効なULIDを指定してください。',
    'uuid' => ':attributeには有効なUUIDを指定してください。',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
