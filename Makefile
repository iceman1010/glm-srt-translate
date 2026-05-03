.PHONY: build clean

build:
	./vendor/bin/box compile

clean:
	rm -f zai-srt-translate.phar
